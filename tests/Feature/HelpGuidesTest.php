<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Source;
use App\Support\BotDraft;
use App\Support\Contact;
use App\Support\Guides;
use App\Support\Onboarding;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Aide et guides, boutons « besoin d'aide », brouillon de création et mise en route : ce que les clients voient pour se débrouiller. */
class HelpGuidesTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /* ---------- Pages d'aide publiques ---------- */

    public function test_the_help_hub_lists_the_two_public_guides_and_no_internal_one(): void
    {
        $this->get(route('help.index'))->assertOk()
            ->assertSee("Guide d'utilisation", false)->assertSee('Guide du développeur')
            ->assertSee(route('help.client'), false)->assertSee(route('help.developer'), false)
            ->assertDontSee('Guide du super admin')->assertDontSee("Guide de l'équipe", false);
    }

    public function test_the_client_guide_page_has_a_table_of_contents_callouts_and_the_signature(): void
    {
        $this->get(route('help.client'))->assertOk()
            ->assertSee('Créer votre assistant')
            ->assertSee('callout', false)
            ->assertSee(Guides::SIGNATURE)
            ->assertSee('Nous contacter');
    }

    public function test_the_developer_guide_page_documents_the_api(): void
    {
        $this->get(route('help.developer'))->assertOk()->assertSee('/api/v1/chat', false)->assertSee('Authorization: Bearer', false);
    }

    public function test_internal_guides_are_not_reachable_from_the_public_route(): void
    {
        $this->get('/aide/guide-admin')->assertNotFound();
        $this->get('/aide/guide-super-admin')->assertNotFound();
    }

    public function test_the_pdf_links_only_appear_once_the_pdf_exists(): void
    {
        $this->assertFileExists(public_path('documents/Kouma-Guide-Client.pdf'), 'lancer : php artisan guides:build --url=https://kouma.site');
        $this->assertFileExists(public_path('documents/Kouma-Guide-Developpeur.pdf'));

        $this->get(route('help.client'))->assertSee('documents/Kouma-Guide-Client.pdf', false);
        $this->get(route('help.developer'))->assertSee('documents/Kouma-Guide-Developpeur.pdf', false);
    }

    public function test_the_public_pdfs_are_real_pdfs_without_a_local_address(): void
    {
        foreach (['Kouma-Guide-Client.pdf', 'Kouma-Guide-Developpeur.pdf'] as $name) {
            $this->assertStringStartsWith('%PDF', (string) file_get_contents(public_path('documents/'.$name), false, null, 0, 5));
            $this->assertGreaterThan(50000, filesize(public_path('documents/'.$name)));
        }
    }

    public function test_internal_pdfs_are_never_in_the_public_folder(): void
    {
        foreach (['Kouma-Guide-Equipe.pdf', 'Kouma-Guide-Super-Admin.pdf'] as $name) {
            $this->assertFileDoesNotExist(public_path('documents/'.$name));
            $this->assertFileDoesNotExist(public_path($name));
        }
    }

    /* ---------- Guides de l'équipe ---------- */

    public function test_staff_read_the_team_guide_but_only_the_super_admin_reads_the_owner_guide(): void
    {
        $this->actingAs($this->staff())->get(route('staff.guide', 'admin'))->assertOk()->assertSee(Guides::SIGNATURE);
        $this->actingAs($this->staff())->get(route('staff.guide', 'super-admin'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('staff.guide', 'super-admin'))->assertOk();
    }

    public function test_clients_and_visitors_cannot_open_the_staff_guides(): void
    {
        [, $client] = $this->tenant();

        $this->get(route('staff.guide', 'admin'))->assertRedirect();
        $this->actingAs($client)->get(route('staff.guide', 'admin'))->assertForbidden();
        $this->actingAs($client)->get(route('staff.guide.pdf', 'super-admin'))->assertForbidden();
    }

    public function test_the_staff_pdf_is_served_inline_to_staff_only(): void
    {
        $this->assertFileExists(Guides::pdfPath('admin'), 'lancer : php artisan guides:build --url=https://kouma.site');

        $response = $this->actingAs($this->staff())->get(route('staff.guide.pdf', 'admin'));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->actingAs($this->staff())->get(route('staff.guide.pdf', 'super-admin'))->assertForbidden();
    }

    /* ---------- Texte des guides ---------- */

    public function test_guides_have_no_em_dash(): void
    {
        foreach (array_keys(Guides::all()) as $key) {
            $this->assertStringNotContainsString("\u{2014}", file_get_contents(Guides::path($key)), "tiret cadratin dans le guide {$key}");
        }
    }

    public function test_no_guide_quotes_the_price_of_a_real_offer(): void
    {
        $prices = Plan::all()->flatMap(fn ($plan) => array_values(array_filter(array_map('intval', $plan->prices ?? []))))->unique()->filter(fn ($p) => $p >= 1000)->values();
        $this->assertNotEmpty($prices, 'les offres de test devraient avoir des prix');

        foreach (Guides::all() as $key => $guide) {
            // « 15 000 », « 15.000 », « 15000 » : tous les écrits d'un même montant.
            $digits = preg_replace('/(?<=\d)[\s\x{202F}\x{00A0}.,](?=\d{3}\b)/u', '', file_get_contents(Guides::path($key)));
            foreach ($prices as $price) {
                $this->assertDoesNotMatchRegularExpression('/(?<!\d)'.$price.'(?!\d)/', $digits, "le prix {$price} d'une offre est écrit dans le guide {$key} : renvoyer à la page des tarifs");
            }
        }
    }

    public function test_every_guide_renders_with_unique_accent_free_anchors(): void
    {
        foreach (array_keys(Guides::all()) as $key) {
            $rendered = Guides::render($key);

            $this->assertNotEmpty($rendered['toc'], "sommaire vide : {$key}");
            $ids = array_column($rendered['toc'], 'id');
            $this->assertSame($ids, array_values(array_unique($ids)), "ancres en double : {$key}");
            foreach ($ids as $id) {
                $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $id);
            }
            $this->assertGreaterThan(1000, $rendered['words'], "guide trop court : {$key}");
        }
    }

    public function test_the_client_guide_matches_the_hub_anchors_used_by_the_help_buttons(): void
    {
        $ids = array_column(Guides::render('client')['toc'], 'id');

        $this->assertContains('nous-contacter', $ids);
        $this->assertContains('6-mettre-en-ligne', $ids);
        $this->assertContains('2-creer-votre-assistant', $ids);
    }

    public function test_the_statistics_page_links_to_an_anchor_that_exists_in_the_owner_guide(): void
    {
        $ids = array_column(Guides::render('super-admin')['toc'], 'id');

        $this->assertContains('9-les-statistiques-comprendre-l-audience-et-les-comportements', $ids);
        $this->actingAs($this->admin())->get(route('admin.statistics.index'))->assertSee('#9-les-statistiques-comprendre-l-audience-et-les-comportements', false);
    }

    public function test_french_typography_keeps_signs_attached_but_leaves_code_alone(): void
    {
        $html = Guides::render('developpeur')['html'];

        $this->assertStringContainsString("\u{00A0}:", $html);
        $this->assertStringNotContainsString('<code>Authorization:&nbsp;', $html);
        $this->assertDoesNotMatchRegularExpression('/<pre[^>]*>[^<]*\x{00A0}/u', $html, 'pas d\'espace insécable dans un bloc de code');
    }

    public function test_callouts_start_with_a_capital_and_carry_their_kind(): void
    {
        $html = Guides::render('client')['html'];

        $this->assertStringContainsString('callout-help', $html);
        $this->assertDoesNotMatchRegularExpression('/callout-title">[^<]+<\/strong>\s*\p{Ll}/u', $html);
    }

    public function test_the_signature_is_defined_once(): void
    {
        $this->assertSame('Tout droit de Kouma', Guides::SIGNATURE);
        $this->assertStringContainsString("Guides::SIGNATURE", file_get_contents(resource_path('views/guides/print.blade.php')));
    }

    /* ---------- « Besoin d'aide » ---------- */

    public function test_contact_links_carry_the_context_and_only_exist_when_configured(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->set('brand.email', null);
        $settings->set('brand.whatsapp', null);
        config(['brand.email' => '', 'brand.whatsapp' => '']);

        $this->assertNull(Contact::mailto('Question'));
        $this->assertNull(Contact::whatsappLink('Question'));

        $settings->set('brand.email', 'equipe@kouma.example');
        $settings->set('brand.whatsapp', '+226 70 00 00 00');

        [, $client] = $this->tenant('Boutique Awa');
        $this->actingAs($client);

        $mail = urldecode((string) Contact::mailto('Question', 'Je bloque'));
        $this->assertStringStartsWith('mailto:equipe@kouma.example', $mail);
        $this->assertStringContainsString('Espace : Boutique Awa', $mail);
        $this->assertStringContainsString('Compte : '.$client->email, $mail);

        $this->assertStringStartsWith('https://wa.me/22670000000', (string) Contact::whatsappLink('Question'));
    }

    public function test_the_dashboard_offers_help_the_site_link_and_the_install_invitation(): void
    {
        [, $client] = $this->tenant('Boutique Awa');
        app(PlatformSettings::class)->set('brand.email', 'equipe@kouma.example');

        $this->actingAs($client)->get(route('dashboard'))->assertOk()
            ->assertSee("Besoin d'aide", false)
            ->assertSee(route('help.client'), false)
            ->assertSee('equipe@kouma.example', false)
            ->assertSee('Voir le site')
            ->assertSee('notifications', false);
    }

    public function test_the_install_invitation_disappears_once_the_app_is_installed(): void
    {
        [, $client] = $this->tenant('Boutique Awa');

        $this->actingAs($client)->get(route('dashboard'))->assertSee('Plus tard');

        $client->forceFill(['pwa_installed_at' => now()])->save();
        $this->actingAs($client->fresh())->get(route('dashboard'))->assertDontSee('kouma-install-card', false);
    }

    /* ---------- Brouillon de création ---------- */

    public function test_the_draft_is_saved_as_you_type_and_found_again(): void
    {
        [, $client] = $this->tenant('Boutique Awa');

        $this->actingAs($client)->postJson(route('bots.draft.save'), ['name' => 'Awa Boutique', 'city' => 'Ouagadougou'])
            ->assertOk()->assertJson(['saved' => true]);

        $this->actingAs($client)->get(route('bots.create'))->assertOk()->assertSee('Awa Boutique')->assertSee('Ouagadougou');
        $this->actingAs($client)->get(route('dashboard'))->assertSee('Reprendre la création');
        $this->actingAs($client)->get(route('bots.index'))->assertSee('Reprendre la création');
    }

    public function test_leaving_for_later_returns_to_the_dashboard_with_a_message(): void
    {
        [, $client] = $this->tenant('Boutique Awa');

        $this->actingAs($client)->post(route('bots.draft.save'), ['name' => 'Awa Boutique', 'leave' => '1'])
            ->assertRedirect(route('dashboard'))->assertSessionHas('status');
    }

    public function test_an_empty_form_leaves_no_draft_and_a_draft_can_be_discarded(): void
    {
        [$workspace, $client] = $this->tenant('Boutique Awa');

        $this->actingAs($client)->postJson(route('bots.draft.save'), ['name' => ''])->assertJson(['saved' => false]);
        $this->assertNull(BotDraft::get($client, $workspace));

        BotDraft::put($client, $workspace, ['name' => 'Awa Boutique']);
        $this->actingAs($client)->delete(route('bots.draft.discard'))->assertRedirect(route('bots.create'));
        $this->assertNull(BotDraft::get($client, $workspace));
    }

    public function test_a_draft_keeps_only_the_creation_fields_and_belongs_to_one_person(): void
    {
        [$workspace, $client] = $this->tenant('Boutique Awa');
        [, $other] = $this->tenant('Boutique B');

        $draft = BotDraft::put($client, $workspace, ['name' => 'Awa', 'is_admin' => '1', 'workspace_id' => '999', 'description' => str_repeat('x', 5000)]);

        $this->assertSame(['name', 'description'], array_keys($draft['fields']));
        $this->assertLessThanOrEqual(600, mb_strlen($draft['fields']['description']));
        $this->assertNull(BotDraft::get($other, $workspace), 'le brouillon d\'une personne n\'est pas celui d\'une autre');
    }

    public function test_creating_the_assistant_erases_the_draft(): void
    {
        [$workspace, $client] = $this->tenant('Boutique Awa');
        BotDraft::put($client, $workspace, ['name' => 'Awa Boutique']);

        $this->actingAs($client)->post(route('bots.store'), [
            'name' => 'Awa Boutique', 'language' => 'fr', 'sector' => 'commerce', 'tone' => 'chaleureux', 'formality' => 'vous',
            'emojis' => 'light', 'length' => 'balanced', 'languages' => ['fr'],
        ])->assertRedirect();

        $this->assertNull(BotDraft::get($client, $workspace));
    }

    /* ---------- Mise en route ---------- */

    public function test_the_onboarding_follows_what_really_exists(): void
    {
        [, $client, $bot] = $this->tenant('Boutique Awa');

        $before = Onboarding::for($bot, $client);
        $this->assertSame(0, $before['done']);
        $this->assertSame('profile', $before['next']['key']);

        $bot->update(['profile' => ['description' => 'Boutique de pagnes à Ouagadougou']]);
        $this->teach($bot, 'Horaires', 'Nous ouvrons du lundi au samedi de huit heures à dix-huit heures.');

        $after = Onboarding::for($bot->fresh(), $client);
        $this->assertSame(2, $after['done']);
        $this->assertSame(40, $after['percent']);
        $this->assertSame('test', $after['next']['key']);
        $this->assertTrue(Source::withoutGlobalScopes()->where('bot_id', $bot->id)->where('status', Source::READY)->exists());
    }

    public function test_the_dashboard_shows_the_onboarding_of_the_first_unfinished_assistant(): void
    {
        [, $client, $bot] = $this->tenant('Boutique Awa');

        $this->actingAs($client)->get(route('dashboard'))->assertOk()->assertSee('Mise en route')->assertSee($bot->name);
    }

    /* ---------- Référencement ---------- */

    public function test_help_pages_are_in_the_sitemap_with_a_valid_title_and_description(): void
    {
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString(route('help.index'), $sitemap);
        $this->assertStringContainsString(route('help.client'), $sitemap);
        $this->assertStringContainsString(route('help.developer'), $sitemap);

        foreach ([route('help.index'), route('help.client'), route('help.developer')] as $url) {
            $html = $this->get($url)->getContent();
            preg_match('#<title>(.*?)</title>#s', $html, $title);
            preg_match('#<meta name="description" content="([^"]*)"#', $html, $description);

            $this->assertLessThanOrEqual(62, mb_strlen(html_entity_decode(trim($title[1] ?? ''))), "titre trop long : {$url}");
            $this->assertGreaterThanOrEqual(70, mb_strlen(html_entity_decode($description[1] ?? '')), "description trop courte : {$url}");
            $this->assertLessThanOrEqual(165, mb_strlen(html_entity_decode($description[1] ?? '')), "description trop longue : {$url}");
            $this->assertStringContainsString('<link rel="canonical"', $html);
        }
    }

    public function test_the_help_pages_are_listed_for_ai_assistants(): void
    {
        $this->get('/llms.txt')->assertOk()->assertSee('/aide', false);
    }

    public function test_no_em_dash_in_the_help_views_and_components(): void
    {
        foreach (['resources/views/help', 'resources/views/staff', 'resources/views/guides', 'resources/views/components/help-card.blade.php', 'resources/views/components/install-card.blade.php', 'resources/views/components/draft-card.blade.php', 'resources/views/components/onboarding-card.blade.php', 'app/Support/Guides.php', 'app/Support/Contact.php', 'app/Support/BotDraft.php', 'app/Support/Onboarding.php'] as $path) {
            $files = is_dir(base_path($path)) ? collect(File::allFiles(base_path($path)))->map->getPathname()->all() : [base_path($path)];
            foreach ($files as $file) {
                $this->assertStringNotContainsString("\u{2014}", file_get_contents($file), "tiret cadratin dans {$file}");
            }
        }
    }
}
