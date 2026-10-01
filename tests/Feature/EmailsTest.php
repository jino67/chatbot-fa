<?php

namespace Tests\Feature;

use App\Mail\Notice;
use App\Models\User;
use App\Support\MailCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Les e-mails de la plateforme : un seul gabarit aux couleurs de la marque, une version texte, et un aperçu pour tout vérifier. */
class EmailsTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function notice(array $over = []): Notice
    {
        return new Notice(...($over + [
            'subjectLine' => 'Votre abonnement se termine',
            'heading' => 'Votre abonnement se termine dans 3 jours',
            'paragraphs' => ['Renouvelez-le pour ne pas interrompre vos assistants.'],
            'actionLabel' => 'Renouveler mon abonnement',
            'actionUrl' => 'https://kouma.site/billing',
            'facts' => ['Espace' => 'Boutique Awa', 'Offre' => 'Pro'],
            'greetingName' => 'Awa Ouédraogo',
            'tone' => 'warning',
            'reason' => 'Vous recevez ce message parce que vous êtes le propriétaire de cet espace.',
        ]));
    }

    /* ---------- Le gabarit ---------- */

    public function test_the_template_carries_the_brand_the_signature_the_button_and_the_facts(): void
    {
        $html = $this->notice()->render();

        foreach (['kouma', 'email-mark.png', 'Tout droit de Kouma', 'Votre abonnement se termine dans 3 jours', 'Bonjour Awa,', 'Renouveler mon abonnement', 'https://kouma.site/billing', 'Boutique Awa', 'propriétaire de cet espace', '#FFB400', '#2340D9'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('<meta name="viewport"', $html, 'lisible sur un téléphone');
        $this->assertStringContainsString('lang="fr"', $html);
    }

    public function test_the_preheader_is_hidden_text_for_the_inbox_preview(): void
    {
        $this->assertStringContainsString('display:none', $this->notice()->render());
        $this->assertStringContainsString('Renouvelez-le pour ne pas interrompre vos assistants.', $this->notice()->render());
    }

    public function test_the_template_escapes_everything_it_is_given(): void
    {
        $html = $this->notice(['heading' => '<script>alert(1)</script>', 'paragraphs' => ['<img src=x onerror=alert(1)>'], 'facts' => ['Espace' => '<b>x</b>'], 'actionUrl' => 'https://kouma.site/x?a=1&b="2"'])->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_plain_text_version_exists_and_has_the_essentials(): void
    {
        \Illuminate\Support\Facades\Mail::to('awa@example.test')->send($this->notice());
        $message = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();

        $text = $message->getTextBody();
        $this->assertStringContainsString('Votre abonnement se termine dans 3 jours', $text);
        $this->assertStringContainsString('Bonjour Awa,', $text);
        $this->assertStringContainsString('Renouveler mon abonnement : https://kouma.site/billing', $text);
        $this->assertStringContainsString('Espace : Boutique Awa', $text);
        $this->assertStringContainsString('Tout droit de Kouma', $text);
        $this->assertStringNotContainsString('<', $text, 'aucune balise dans la version texte');
        $this->assertStringContainsString('Bonjour Awa,', $message->getHtmlBody());
    }

    public function test_the_greeting_uses_the_first_name_only_or_none(): void
    {
        $this->assertSame('Bonjour Awa,', $this->notice(['greetingName' => 'Awa Ouédraogo'])->greeting());
        $this->assertSame('Bonjour,', $this->notice(['greetingName' => null])->greeting());
        $this->assertSame('Bonjour,', $this->notice(['greetingName' => '   '])->greeting());
    }

    public function test_an_unknown_tone_falls_back_to_info(): void
    {
        $this->assertSame('info', $this->notice(['tone' => 'flashy'])->tone);
        $this->assertSame('danger', $this->notice(['tone' => 'danger'])->tone);
    }

    public function test_a_message_without_button_or_facts_still_renders(): void
    {
        $html = $this->notice(['actionLabel' => null, 'actionUrl' => null, 'facts' => [], 'paragraphs' => []])->render();

        $this->assertStringContainsString('Votre abonnement se termine dans 3 jours', $html);
        $this->assertStringNotContainsString('Le bouton ne s&#039;ouvre pas', $html);
    }

    public function test_the_footer_links_to_the_notification_preferences_unless_turned_off(): void
    {
        $this->assertStringContainsString(route('notifications.preferences'), $this->notice()->render());
        $this->assertStringNotContainsString(route('notifications.preferences'), $this->notice(['settings' => false])->render());
    }

    /* ---------- Tous les e-mails ---------- */

    public function test_every_email_of_the_platform_renders_with_the_brand_and_no_forbidden_text(): void
    {
        foreach (array_keys(MailCatalog::all()) as $key) {
            $html = MailCatalog::html($key);

            $this->assertNotSame('', $html, $key);
            $this->assertStringContainsString('Tout droit de Kouma', $html, "signature absente : {$key}");
            $this->assertStringContainsString('email-mark.png', $html, "bandeau absent : {$key}");
            $this->assertStringNotContainsString("\u{2014}", $html, "tiret cadratin : {$key}");
            $this->assertStringNotContainsString('{{', $html, "balise non interprétée : {$key}");
            $this->assertNotSame('', MailCatalog::subject($key), "objet vide : {$key}");
        }
    }

    public function test_the_catalog_lists_both_audiences_and_every_event_email(): void
    {
        $keys = array_keys(MailCatalog::all());

        foreach (['welcome', 'lead', 'handoff', 'subscriptionEnding', 'subscriptionEnded', 'trialEnded', 'paymentReceived', 'whatsappBlocked', 'whatsappActivated', 'password-reset', 'planRequested', 'walletLow', 'digest'] as $needed) {
            $this->assertContains($needed, $keys);
        }
        $this->assertSame(['client', 'team'], array_values(array_unique(array_column(MailCatalog::all(), 'group'))));
    }

    public function test_the_lead_and_handoff_emails_keep_what_the_team_needs_to_act(): void
    {
        $lead = MailCatalog::html('lead');
        $this->assertStringContainsString('Commande à confirmer', $lead);
        $this->assertStringContainsString('2 boubous brodés', $lead);
        $this->assertStringContainsString('Voir la conversation', $lead);
        $this->assertStringContainsString('Toutes les demandes', $lead);

        $handoff = MailCatalog::html('handoff');
        $this->assertStringContainsString('Un client attend une réponse humaine', $handoff);
        $this->assertStringContainsString('Répondre depuis la boîte de réception', $handoff);
        $this->assertStringContainsString('demande explicite du client', $handoff);
    }

    public function test_the_receipt_shows_amount_method_reference_and_period(): void
    {
        $html = MailCatalog::html('paymentReceived');

        $this->assertStringContainsString('Paiement reçu, merci !', $html);
        $this->assertStringContainsString('OM-2026-0412', $html);
        $this->assertStringContainsString('40', $html);
        $this->assertStringContainsString('Période', $html);
        $this->assertStringContainsString('conservez cet e-mail', mb_strtolower($html));
    }

    public function test_the_password_reset_mail_uses_the_template_and_the_link_works(): void
    {
        $user = User::factory()->create(['name' => 'Awa Ouédraogo']);

        $user->sendPasswordResetNotification('jeton-de-test');

        $message = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $this->assertStringContainsString('Choisir un nouveau mot de passe', $message->getHtmlBody());
        $this->assertStringContainsString('Tout droit de Kouma', $message->getHtmlBody());
        $this->assertStringContainsString('jeton-de-test', $message->getHtmlBody());
        $this->assertStringContainsString('Bonjour Awa,', $message->getHtmlBody());
        $this->assertStringContainsString('jeton-de-test', $message->getTextBody());
    }

    public function test_no_old_plain_text_mail_remains_in_the_code(): void
    {
        $offenders = [];
        foreach (File::allFiles(base_path('app')) as $file) {
            if (str_contains($file->getContents(), 'Mail::raw(')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'tout e-mail passe par le gabarit de la marque (Notice) : Mail::raw est interdit');
    }

    /* ---------- Aperçu (super administrateur) ---------- */

    public function test_the_preview_page_is_for_the_super_admin_only(): void
    {
        [, $client] = $this->tenant();

        $this->get(route('admin.emails.index'))->assertRedirect();
        $this->actingAs($client)->get(route('admin.emails.index'))->assertForbidden();
        $this->actingAs($this->staff(User::ADMIN))->get(route('admin.emails.index'))->assertForbidden();
        $this->actingAs($this->staff(User::ADMIN))->get(route('admin.emails.show', 'welcome'))->assertForbidden();
        $this->actingAs($this->staff(User::ADMIN))->post(route('admin.emails.send', 'welcome'))->assertForbidden();
    }

    public function test_the_super_admin_sees_the_list_and_each_preview(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.emails.index'))->assertOk()
            ->assertSee('E-mails de la plateforme')->assertSee('Reçu de paiement')->assertSee('Pour l&#039;équipe', false)->assertSee('L\'envoi réel est désactivé', false);

        foreach (array_keys(MailCatalog::all()) as $key) {
            $response = $this->actingAs($admin)->get(route('admin.emails.show', $key))->assertOk();
            $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
            $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
            $this->actingAs($admin)->get(route('admin.emails.index', ['modele' => $key]))->assertOk();
        }

        $this->actingAs($admin)->get(route('admin.emails.show', 'inconnu'))->assertNotFound();
    }

    public function test_the_super_admin_can_send_an_example_to_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.emails.send', 'paymentReceived'))->assertRedirect()->assertSessionHas('status');

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame($admin->email, $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringStartsWith('[Aperçu] Reçu de paiement', $messages[0]->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('OM-2026-0412', $messages[0]->getOriginalMessage()->getHtmlBody());
        $this->actingAs($admin)->post(route('admin.emails.send', 'inconnu'))->assertNotFound();
    }

    public function test_the_sidebar_links_the_super_admin_to_the_emails(): void
    {
        $this->actingAs($this->admin())->get(route('admin.overview'))->assertOk()->assertSee(route('admin.emails.index'), false);
    }
}
