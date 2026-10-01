<?php

namespace Tests\Feature;

use App\Channels\WhatsApp\GatewayException;
use App\Channels\WhatsApp\MetaCloudGateway;
use App\Channels\WhatsApp\TemplateLibrary;
use App\Channels\WhatsApp\TemplateProvisioner;
use App\Channels\WhatsApp\TwilioGateway;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\ChannelRequest;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Bibliothèque de modèles WhatsApp : règles de Meta respectées, création par paquet (Meta et Twilio), écran, activation. */
class WhatsAppTemplateLibraryTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private Bot $bot;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.whatsapp.template_pause_ms' => 0]);
        [, $this->owner, $this->bot] = $this->tenant('Boutique Awa', 'pro');
        $this->bot->update(['sector' => 'commerce', 'profile' => ['website' => 'www.boutique-awa.test', 'phone' => '+226 70 00 00 00']]);
    }

    private function metaChannel(): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_META,
            'status' => Channel::ACTIVE, 'display_phone' => '+226 70 00 00 00', 'external_ref' => '109876543210',
            'credentials' => ['access_token' => 'EAAtoken', 'waba_id' => '555'],
        ]);
    }

    private function twilioChannel(): Channel
    {
        return Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'type' => Channel::WHATSAPP_TWILIO,
            'status' => Channel::ACTIVE, 'display_phone' => '+14155238886', 'external_ref' => '14155238886',
            'credentials' => ['account_sid' => 'ACtest', 'auth_token' => 'secret', 'from' => '+14155238886'],
        ]);
    }

    private function fakeMeta(): void
    {
        Http::fake(['graph.facebook.com/*/message_templates' => fn () => Http::response(['id' => (string) random_int(1000, 9999), 'status' => 'PENDING'])]);
    }

    private function fakeTwilio(): void
    {
        Http::fake([
            'content.twilio.com/v1/Content/*/ApprovalRequests/whatsapp' => Http::response(['status' => 'received'], 201),
            'content.twilio.com/v1/Content/*' => Http::response('', 204),
            'content.twilio.com/v1/Content' => fn () => Http::response(['sid' => 'HX'.bin2hex(random_bytes(8))], 201),
        ]);
    }

    /** @return list<HttpRequest> */
    private function sent(string $needle, ?string $method = null): array
    {
        return Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), $needle) && ($method === null || $r->method() === $method))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    /* ---------- La bibliothèque elle-même ---------- */

    public function test_every_template_follows_the_rules_meta_enforces(): void
    {
        $company = ['company' => 'Boutique Awa', 'site' => 'https://boutique-awa.test', 'phone' => '+22670000000'];
        $library = app(TemplateLibrary::class);
        $promo = '/promo|offre|réduction|remise|%|offer|discount|sale\b|gratuit/iu';
        $seen = 0;

        foreach (config('whatsapp_templates.templates') as $key => $template) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,60}$/', $key, 'nom technique');
            $this->assertArrayHasKey($template['group'], config('whatsapp_templates.groups'), "{$key} : groupe");
            $this->assertContains($template['category'], ['UTILITY', 'MARKETING'], "{$key} : catégorie");
            $this->assertNotEmpty($template['title']);
            $this->assertArrayHasKey('fr', $template, "{$key} : le français est obligatoire");

            foreach (TemplateLibrary::LANGUAGES as $language) {
                if (! isset($template[$language])) {
                    continue;
                }
                $seen++;
                $d = $library->definition($key, $language, $company);
                $label = "{$key} ({$language})";

                $this->assertLessThanOrEqual(1024, mb_strlen($d['body']), "{$label} : corps trop long");
                $this->assertStringNotContainsString("\u{2014}", $d['body'].$template['title']);
                $this->assertDoesNotMatchRegularExpression('/\{\{\d+\}\}\s*\{\{\d+\}\}/', $d['body'], "{$label} : deux variables collées");
                $this->assertDoesNotMatchRegularExpression('/^\s*\{\{\d+\}\}|\{\{\d+\}\}\s*$/', $d['body'], "{$label} : commence ou finit par une variable");
                $this->assertStringNotContainsString('{company}', $d['body'], "{$label} : jeton resté");
                $this->assertStringNotContainsString('  ', $d['body'], "{$label} : double espace");

                // Variables numérotées dans l'ordre, un exemple et un libellé chacune.
                $count = MetaCloudGateway::countVariables($d['body']);
                preg_match_all('/\{\{(\d+)\}\}/', $d['body'], $m);
                $numbers = array_values(array_unique(array_map('intval', $m[1])));
                sort($numbers);
                $this->assertSame($count === 0 ? [] : range(1, $count), $numbers, "{$label} : variables dans l'ordre");
                $this->assertCount($count, $d['body_examples'], "{$label} : un exemple par variable");
                $this->assertCount($count, $template[$language]['vars'], "{$label} : un libellé par variable");
                foreach ($d['body_examples'] as $example) {
                    $this->assertNotSame('', trim($example), "{$label} : exemple vide");
                }

                if ($d['footer'] !== null) {
                    $this->assertLessThanOrEqual(60, mb_strlen($d['footer']), "{$label} : pied trop long");
                }
                $quick = 0;
                foreach ($d['buttons'] as $button) {
                    $this->assertLessThanOrEqual(25, mb_strlen($button['text']), "{$label} : bouton trop long");
                    $quick += $button['type'] === 'QUICK_REPLY' ? 1 : 0;
                }
                $this->assertLessThanOrEqual(3, $quick, "{$label} : trois réponses rapides au plus");
                $this->assertNull($d['header'], 'pas de titre : Meta le refuse souvent');

                if ($template['category'] === 'UTILITY') {
                    $this->assertDoesNotMatchRegularExpression($promo, $d['body'], "{$label} : un modèle utilitaire ne fait pas de promotion");
                } else {
                    $this->assertMatchesRegularExpression('/STOP/', (string) $d['footer'], "{$label} : un modèle marketing dit comment ne plus recevoir d'offres");
                }
            }
        }

        $this->assertGreaterThanOrEqual(36, $seen, 'au moins 28 modèles en français et 8 en anglais');
        $this->assertCount(28, $library->keys());
    }

    public function test_the_packs_only_use_existing_templates_and_every_template_belongs_to_a_pack(): void
    {
        $library = app(TemplateLibrary::class);
        $inPacks = [];

        foreach ($library->packs() as $name => $pack) {
            foreach ($pack['keys'] as $key) {
                $this->assertTrue($library->has($key, 'fr'), "{$name} : {$key} n'existe pas");
                $inPacks[$key] = true;
            }
            $this->assertNotEmpty($pack['label']);
            $this->assertNotEmpty($pack['description']);
            // « essentiel » est inclus dans chaque paquet, sans doublon.
            $this->assertSame(array_values(array_unique($library->packKeys($name))), $library->packKeys($name));
            $this->assertSame([], array_diff($library->packs()['essentiel']['keys'], $library->packKeys($name)));
        }

        $this->assertSame([], array_diff($library->keys(), array_keys($inPacks)), 'un modèle hors de tout paquet ne serait jamais proposé');

        foreach (config('whatsapp_templates.pack_by_sector') as $sector => $pack) {
            $this->assertArrayHasKey($sector, config('sectors'), "secteur {$sector}");
            $this->assertArrayHasKey($pack, $library->packs(), "paquet {$pack}");
        }
        foreach (array_keys(config('sectors')) as $sector) {
            $this->assertArrayHasKey($sector, config('whatsapp_templates.pack_by_sector'), "le secteur {$sector} a un paquet");
        }
    }

    public function test_the_company_name_is_written_into_the_text_and_buttons_without_data_disappear(): void
    {
        $library = app(TemplateLibrary::class);

        $with = $library->definition('livraison_en_route', 'fr', ['company' => 'Boutique Awa', 'site' => '', 'phone' => '+22670000000']);
        $this->assertSame([['type' => 'PHONE_NUMBER', 'text' => 'Appeler la boutique', 'phone_number' => '+22670000000']], $with['buttons']);

        $without = $library->definition('livraison_en_route', 'fr', ['company' => 'Boutique Awa', 'site' => '', 'phone' => '']);
        $this->assertSame([], $without['buttons'], 'sans numéro, pas de bouton d\'appel');

        $d = $library->definition('commande_prete', 'fr', ['company' => 'Chez Awa', 'site' => '', 'phone' => '']);
        $this->assertStringContainsString('À très vite chez Chez Awa !', $d['body']);
        $this->assertSame('commande_prete', $d['name']);
        $this->assertSame('UTILITY', $d['category']);

        $this->expectException(\InvalidArgumentException::class);
        $library->definition('commande_prete', 'ar', []);
    }

    public function test_the_context_comes_from_the_assistant_and_its_whatsapp_number(): void
    {
        $library = app(TemplateLibrary::class);

        $context = $library->context($this->bot->fresh(), $this->twilioChannel());

        $this->assertSame('Boutique Awa', $context['company']);
        $this->assertSame('https://www.boutique-awa.test', $context['site']);
        $this->assertSame('+14155238886', $context['phone'], 'le numéro du canal prime sur celui du profil');

        $this->bot->update(['profile' => ['website' => 'pas une adresse', 'phone' => '12']]);
        $empty = $library->context($this->bot->fresh());
        $this->assertSame('', $empty['site']);
        $this->assertSame('', $empty['phone']);
    }

    /* ---------- Création chez Meta ---------- */

    public function test_a_pack_is_created_at_meta_once_and_a_second_run_creates_nothing(): void
    {
        $this->fakeMeta();
        $channel = $this->metaChannel();
        $provisioner = app(TemplateProvisioner::class);
        $keys = app(TemplateLibrary::class)->packKeys('boutique');

        $first = $provisioner->provision($channel, $this->bot, $keys);

        $this->assertCount(14, $first['created']);
        $this->assertSame([], $first['failed']);
        $this->assertCount(14, $this->sent('/message_templates'));
        $stored = WhatsAppTemplate::withoutGlobalScopes()->where('channel_id', $channel->id)->get();
        $this->assertCount(14, $stored);
        $this->assertSame('PENDING', $stored->firstWhere('name', 'commande_prete')->status);
        $this->assertStringContainsString('Boutique Awa', $stored->firstWhere('name', 'commande_prete')->body);

        $second = $provisioner->provision($channel, $this->bot, $keys);
        $this->assertSame([], $second['created']);
        $this->assertCount(14, $second['existing']);
        $this->assertCount(14, $this->sent('/message_templates'), 'aucune nouvelle demande au fournisseur');
    }

    public function test_the_payload_sent_to_meta_carries_examples_buttons_and_the_footer(): void
    {
        $this->fakeMeta();
        app(TemplateProvisioner::class)->provision($this->metaChannel(), $this->bot, ['promotion_du_moment', 'livraison_en_route']);

        $promo = collect($this->sent('/message_templates'))->map(fn ($r) => $r->data())->firstWhere('name', 'promotion_du_moment');
        $components = collect($promo['components'])->keyBy('type');

        $this->assertSame('MARKETING', $promo['category']);
        $this->assertSame('fr', $promo['language']);
        $this->assertSame([['Awa', '-20 % sur les pagnes', 'dimanche']], $components['BODY']['example']['body_text']);
        $this->assertSame("Répondez STOP pour ne plus recevoir d'offres", $components['FOOTER']['text']);
        $this->assertCount(2, $components['BUTTONS']['buttons']);

        $delivery = collect($this->sent('/message_templates'))->map(fn ($r) => $r->data())->firstWhere('name', 'livraison_en_route');
        $this->assertSame('PHONE_NUMBER', collect($delivery['components'])->firstWhere('type', 'BUTTONS')['buttons'][0]['type']);
    }

    public function test_one_refused_template_does_not_stop_the_others(): void
    {
        $channel = $this->metaChannel();
        $provisioner = app(TemplateProvisioner::class);

        $calls = 0;
        Http::fake(['graph.facebook.com/*/message_templates' => function () use (&$calls) {
            return ++$calls === 2
                ? Http::response(['error' => ['message' => 'Contenu refusé', 'code' => 100]], 400)
                : Http::response(['id' => 'ok'.$calls, 'status' => 'PENDING']);
        }]);
        $partial = $provisioner->provision($channel, $this->bot, ['message_bien_recu', 'suite_a_votre_demande', 'merci_visite', 'demande_avis']);
        $this->assertCount(3, $partial['created']);
        $this->assertSame(['suite_a_votre_demande'], array_keys($partial['failed']));
    }

    public function test_three_refusals_in_a_row_stop_the_run(): void
    {
        // Le fournisseur refuse tout (jeton invalide) : on s'arrête après trois échecs de suite.
        Http::fake(['graph.facebook.com/*/message_templates' => Http::response(['error' => ['message' => 'Jeton invalide', 'code' => 190]], 401)]);
        $all = app(TemplateProvisioner::class)->provision($this->metaChannel(), $this->bot, app(TemplateLibrary::class)->packKeys('boutique'));
        $this->assertCount(3, $all['failed']);
        $this->assertCount(3, $this->sent('/message_templates'), 'pas d\'insistance quand tout est refusé');
    }

    public function test_unknown_keys_and_missing_languages_are_skipped_not_fatal(): void
    {
        $this->fakeMeta();

        $result = app(TemplateProvisioner::class)->provision($this->metaChannel(), $this->bot, ['rdv_annule', 'nimporte_quoi', 'message_bien_recu'], 'en');

        $this->assertSame(['message_bien_recu'], $result['created']);
        $this->assertEqualsCanonicalizing(['rdv_annule', 'nimporte_quoi'], $result['skipped']);
        $this->assertSame('en', WhatsAppTemplate::withoutGlobalScopes()->firstOrFail()->language);
    }

    /* ---------- Création chez Twilio ---------- */

    public function test_a_template_with_quick_replies_is_created_at_twilio_then_submitted_for_whatsapp_approval(): void
    {
        $this->fakeTwilio();
        $channel = $this->twilioChannel();

        $result = app(TemplateProvisioner::class)->provision($channel, $this->bot, ['rdv_rappel']);

        $this->assertSame(['rdv_rappel'], $result['created']);
        $content = $this->sent('v1/Content', 'POST')[0]->data();
        $this->assertSame('rdv_rappel', $content['friendly_name']);
        $this->assertSame('fr', $content['language']);
        $this->assertSame(['1' => 'Awa', '2' => '10 h 30', '3' => 'coupe et brushing'], $content['variables']);
        $quick = $content['types']['twilio/quick-reply'];
        $this->assertStringContainsString("Merci de confirmer votre venue.", $quick['body']);
        $this->assertSame(['je_confirme', 'reporter', 'annuler'], array_column($quick['actions'], 'id'));
        $this->assertSame(['Je confirme', 'Reporter', 'Annuler'], array_column($quick['actions'], 'title'));

        $approval = collect($this->sent('/ApprovalRequests/whatsapp'))->first()->data();
        $this->assertSame(['name' => 'rdv_rappel', 'category' => 'UTILITY'], $approval);

        $local = WhatsAppTemplate::withoutGlobalScopes()->where('name', 'rdv_rappel')->firstOrFail();
        $this->assertSame('PENDING', $local->status);
        $this->assertStringStartsWith('HX', $local->external_id);
        $this->assertSame(3, $local->variables_count);
    }

    public function test_twilio_templates_use_the_text_and_call_to_action_types_and_fold_the_footer_into_the_body(): void
    {
        $this->fakeTwilio();
        app(TemplateProvisioner::class)->provision($this->twilioChannel(), $this->bot, ['message_bien_recu', 'livraison_en_route']);

        $contents = collect($this->sent('v1/Content', 'POST'))->map(fn ($r) => $r->data())->filter(fn ($d) => isset($d['types']))->keyBy('friendly_name');

        $text = $contents['message_bien_recu']['types']['twilio/text']['body'];
        $this->assertStringEndsWith("\n\n_Boutique Awa_", $text, 'le pied de message est écrit en italique à la fin du corps');

        $cta = $contents['livraison_en_route']['types']['twilio/call-to-action'];
        $this->assertSame([['type' => 'PHONE_NUMBER', 'title' => 'Appeler la boutique', 'phone' => '+14155238886']], $cta['actions']);
    }

    public function test_a_failed_approval_request_removes_the_content_it_created_and_mixed_buttons_are_explained(): void
    {
        Http::fake([
            'content.twilio.com/v1/Content/*/ApprovalRequests/whatsapp' => Http::response(['message' => 'Catégorie invalide'], 400),
            'content.twilio.com/v1/Content/*' => Http::response('', 204),
            'content.twilio.com/v1/Content' => Http::response(['sid' => 'HXorphan'], 201),
        ]);
        $channel = $this->twilioChannel();

        $result = app(TemplateProvisioner::class)->provision($channel, $this->bot, ['merci_visite']);

        $this->assertSame([], $result['created']);
        $this->assertStringContainsString('Catégorie invalide', $result['failed']['merci_visite']);
        $this->assertCount(1, $this->sent('/Content/HXorphan', 'DELETE'), 'pas de modèle orphelin dans le compte Twilio');
        $this->assertSame(0, WhatsAppTemplate::withoutGlobalScopes()->count());

        $gateway = new TwilioGateway($channel);
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('mélanger');
        $gateway->createTemplate([
            'name' => 'mixte', 'language' => 'fr', 'category' => 'UTILITY', 'body' => 'Bonjour, voici un test.', 'body_examples' => [],
            'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Oui'], ['type' => 'URL', 'text' => 'Voir', 'url' => 'https://exemple.test']],
        ]);
    }

    /* ---------- Écran ---------- */

    public function test_the_page_shows_the_library_with_previews_statuses_and_the_pack_for_the_business(): void
    {
        $channel = $this->metaChannel();
        WhatsAppTemplate::withoutGlobalScopes()->create([
            'workspace_id' => $channel->workspace_id, 'channel_id' => $channel->id, 'name' => 'commande_prete', 'language' => 'fr',
            'category' => 'UTILITY', 'status' => WhatsAppTemplate::PENDING, 'body' => 'x', 'variables_count' => 0,
        ]);

        $page = $this->actingAs($this->owner)->get(route('templates.index', $this->bot))->assertOk();

        $page->assertSee('Bibliothèque de modèles');
        $page->assertSee('28 messages déjà rédigés');
        $page->assertSee('Boutique et commerce');
        $page->assertSee('Conseillé pour vous');
        $page->assertSee('Commande prête à retirer');
        $page->assertSee('Appeler la boutique');
        $page->assertSee('Joyeux anniversaire');
        $page->assertSee('En attente de Meta');
        $page->assertSee("À renseigner à l'envoi : Prénom du client, Numéro de commande, Adresse de retrait.", false);
        $this->assertStringNotContainsString('value="commande_prete"', $page->getContent(), 'un modèle déjà créé ne se coche plus');
        $this->assertStringContainsString('value="commande_recue"', $page->getContent());

        $english = $this->actingAs($this->owner)->get(route('templates.index', [$this->bot, 'langue' => 'en']))->assertOk();
        $english->assertSee('Hello');
        $english->assertSee('12 messages');
    }

    public function test_adding_templates_and_packs_from_the_page(): void
    {
        $this->fakeMeta();
        $this->metaChannel();

        $this->actingAs($this->owner)->post(route('templates.add', $this->bot), ['keys' => ['commande_recue', 'rdv_rappel'], 'language' => 'fr'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, '2 modèle(s) envoyé(s)'));
        $this->assertSame(2, WhatsAppTemplate::withoutGlobalScopes()->count());

        $this->actingAs($this->owner)->post(route('templates.pack', $this->bot), ['pack' => 'essentiel', 'language' => 'fr'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, '4 modèle(s) envoyé(s)'));

        $this->actingAs($this->owner)->post(route('templates.pack', $this->bot), ['pack' => 'essentiel', 'language' => 'fr'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, '4 déjà présent(s)'));

        $this->actingAs($this->owner)->post(route('templates.add', $this->bot), ['keys' => ['nimporte_quoi'], 'language' => 'fr'])->assertSessionHasErrors('keys.0');
        $this->actingAs($this->owner)->post(route('templates.add', $this->bot), ['language' => 'fr'])->assertSessionHasErrors('keys');
        $this->actingAs($this->owner)->post(route('templates.pack', $this->bot), ['pack' => 'inconnu', 'language' => 'fr'])->assertSessionHasErrors('pack');
    }

    public function test_the_providers_refusals_are_shown_not_swallowed(): void
    {
        $this->metaChannel();
        Http::fake(['graph.facebook.com/*/message_templates' => Http::response(['error' => ['message' => 'Jeton expiré', 'code' => 190]], 401)]);

        $this->actingAs($this->owner)->post(route('templates.pack', $this->bot), ['pack' => 'essentiel', 'language' => 'fr'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'refusé(s) par le fournisseur') && str_contains($m, 'Jeton expiré'));
    }

    public function test_the_library_is_reserved_to_offers_with_templates_and_to_the_owner_of_the_assistant(): void
    {
        $this->metaChannel();

        [, $stranger] = $this->tenant('Autre entreprise', 'pro');
        $this->actingAs($stranger)->post(route('templates.pack', $this->bot), ['pack' => 'essentiel', 'language' => 'fr'])->assertNotFound();
        $this->actingAs($stranger)->get(route('templates.index', $this->bot))->assertNotFound();

        $this->bot->workspace->update(['plan' => 'essentiel']);
        $this->actingAs($this->owner)->get(route('templates.index', $this->bot))->assertOk()->assertSee("Les modèles sont inclus dans l'offre Pro", false)->assertDontSee('Bibliothèque de modèles');
        $this->actingAs($this->owner)->post(route('templates.pack', $this->bot), ['pack' => 'essentiel', 'language' => 'fr'])->assertForbidden();
    }

    /* ---------- Commande et activation ---------- */

    public function test_the_command_lists_the_library_and_creates_a_pack_on_a_channel(): void
    {
        $this->artisan('whatsapp:templates', ['--liste' => true])->expectsOutputToContain('commande_prete')->expectsOutputToContain('boutique')->assertSuccessful();

        $this->artisan('whatsapp:templates', ['bot' => $this->bot->id, '--pack' => 'essentiel'])->assertFailed(); // pas de canal

        $this->fakeTwilio();
        $this->twilioChannel();
        $this->artisan('whatsapp:templates', ['bot' => $this->bot->id, '--pack' => 'essentiel', '--cle' => ['rdv_rappel']])
            ->expectsOutputToContain('message_bien_recu')
            ->assertSuccessful();
        $this->assertSame(5, WhatsAppTemplate::withoutGlobalScopes()->count());

        $this->artisan('whatsapp:templates', ['bot' => $this->bot->id, '--pack' => 'nimporte'])->assertFailed();
        $this->artisan('whatsapp:templates', ['bot' => $this->bot->id])->assertFailed();
    }

    public function test_activating_a_channel_submits_the_base_pack_and_the_pack_of_the_business(): void
    {
        config(['platform.whatsapp.auto_templates' => true]);
        $this->fakeTwilio();
        $request = ChannelRequest::withoutGlobalScopes()->create([
            'workspace_id' => $this->bot->workspace_id, 'bot_id' => $this->bot->id, 'requester_id' => $this->owner->id,
            'business_name' => 'Boutique Awa', 'phone_number' => '+226 70 00 00 00', 'country' => 'Burkina Faso',
        ]);
        $admin = User::factory()->create(['role' => User::SUPER_ADMIN]);

        $this->actingAs($admin)->put(route('admin.requests.update', $request->id), [
            'provider' => Channel::WHATSAPP_TWILIO, 'status' => 'active', 'display_phone' => '+14155238886', 'request_status' => 'active',
            'account_sid' => 'ACsub', 'auth_token' => 'secret', 'from' => '+14155238886',
        ])->assertRedirect();

        // Commerce : l'essentiel (4) et la boutique (10), soit 14 modèles, sans rien demander au client.
        $this->assertSame(14, WhatsAppTemplate::withoutGlobalScopes()->count());
        $this->assertCount(14, $this->sent('/ApprovalRequests/whatsapp'));

        // Une mise à jour du canal ne relance rien.
        $this->actingAs($admin)->put(route('admin.requests.update', $request->id), [
            'provider' => Channel::WHATSAPP_TWILIO, 'status' => 'active', 'display_phone' => '+14155238886', 'request_status' => 'active',
            'account_sid' => 'ACsub', 'auth_token' => '', 'from' => '+14155238886',
        ])->assertRedirect();
        $this->assertCount(14, $this->sent('/ApprovalRequests/whatsapp'));
    }

    public function test_activation_creates_nothing_without_the_templates_feature_or_when_the_switch_is_off(): void
    {
        $this->fakeTwilio();
        $channel = $this->twilioChannel();

        config(['platform.whatsapp.auto_templates' => false]);
        $this->assertNull(app(TemplateProvisioner::class)->autoProvision($channel));

        config(['platform.whatsapp.auto_templates' => true]);
        $this->bot->workspace->update(['plan' => 'essentiel']);
        $this->assertNull(app(TemplateProvisioner::class)->autoProvision($channel), 'les modèles ne font pas partie de cette offre');

        $this->assertSame(0, WhatsAppTemplate::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }
}
