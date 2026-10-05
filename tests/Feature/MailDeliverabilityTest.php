<?php

namespace Tests\Feature;

use App\Mail\Notice;
use App\Services\PlatformSettings;
use App\Services\TwilioWallet;
use App\Support\MailHealth;
use App\Support\MailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Le nom d'expéditeur, le diagnostic SPF, DKIM et DMARC, et la lecture du solde Twilio d'un sous-compte. */
class MailDeliverabilityTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_placeholder_sender_name_is_replaced_by_the_brand(): void
    {
        foreach (['${APP_NAME}', '', '  ', 'Example', 'Laravel', '{{ name }}'] as $bad) {
            $this->assertTrue(MailSender::isPlaceholder($bad), "« {$bad} » est un faux nom");
        }
        $this->assertFalse(MailSender::isPlaceholder('Kouma Support'));

        $this->assertSame('Kouma Support', MailSender::name('Kouma Support'), 'un nom choisi par l\'équipe est respecté');
        $this->assertSame('Kouma', MailSender::name('${APP_NAME}'));

        app(PlatformSettings::class)->set('brand.name', 'Kouma IA');
        $this->assertSame('Kouma IA', MailSender::name('${APP_NAME}'));

        $mail = (new Email)->from(new Address('contac@kouma.site', '${APP_NAME}'))->to('a@b.test')->text('x');
        MailSender::fix($mail);
        $this->assertSame('Kouma IA', $mail->getFrom()[0]->getName());
        $this->assertSame('contac@kouma.site', $mail->getFrom()[0]->getAddress());

        $kept = (new Email)->from(new Address('contac@kouma.site', 'Emma de Kouma'))->to('a@b.test')->text('x');
        MailSender::fix($kept);
        $this->assertSame('Emma de Kouma', $kept->getFrom()[0]->getName());
    }

    public function test_every_real_email_leaves_with_a_proper_sender_name(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'contac@kouma.site', 'mail.from.name' => '${APP_NAME}']);
        app('mail.manager')->forgetMailers();

        Mail::to('client@exemple.bf')->send(new Notice(subjectLine: 'Bonjour', heading: 'Bonjour', paragraphs: ['Un message.']));

        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $from = $sent[0]->getOriginalMessage()->getFrom()[0];
        $this->assertSame('Kouma', $from->getName());
        $this->assertSame('contac@kouma.site', $from->getAddress());
    }

    private function dns(array $zone): callable
    {
        return fn (string $host, int $type) => array_map(
            fn ($value) => $type === DNS_MX ? ['target' => $value] : ['txt' => $value],
            $zone[$host.'|'.($type === DNS_MX ? 'MX' : 'TXT')] ?? [],
        );
    }

    public function test_a_domain_without_spf_dkim_and_dmarc_is_flagged_with_what_to_do(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.name' => 'Kouma']);
        $health = new MailHealth('contac@kouma.site', $this->dns([]), 'kouma.site');

        $checks = collect($health->checks())->keyBy('key');
        foreach (['spf', 'dkim', 'dmarc'] as $key) {
            $this->assertSame('bad', $checks[$key]['status'], $key);
            $this->assertNotEmpty($checks[$key]['fix']);
        }
        $this->assertSame('warn', $checks['mx']['status']);
        $this->assertSame('ok', $checks['alignment']['status']);
        $this->assertSame('bad', MailHealth::summary($checks->values()->all())['level']);
        $this->assertSame(15, MailHealth::summary($checks->values()->all())['score']);
    }

    public function test_a_well_configured_domain_is_green(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.name' => 'Kouma']);
        $health = new MailHealth('contac@kouma.site', $this->dns([
            'kouma.site|MX' => ['mail.kouma.site'],
            'kouma.site|TXT' => ['google-site-verification=abc', 'v=spf1 a mx include:spf.lws.fr ~all'],
            'default._domainkey.kouma.site|TXT' => ['v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQ'],
            '_dmarc.kouma.site|TXT' => ['v=DMARC1; p=quarantine; rua=mailto:contac@kouma.site'],
        ]), 'kouma.site');

        $checks = $health->checks();
        $this->assertSame(['ok'], array_values(array_unique(array_column($checks, 'status'))));
        $this->assertSame(['score' => 100, 'level' => 'ok', 'label' => 'Bien configuré'], MailHealth::summary($checks));
    }

    public function test_weak_or_broken_records_are_told_apart(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.name' => '${APP_NAME}']);
        $health = new MailHealth('contac@kouma.site', $this->dns([
            'kouma.site|MX' => ['mail.kouma.site'],
            'kouma.site|TXT' => ['v=spf1 +all', 'v=spf1 a ~all'],
            '_dmarc.kouma.site|TXT' => ['v=DMARC1; p=none'],
        ]), 'kouma.site');
        $checks = collect($health->checks())->keyBy('key');

        $this->assertSame('bad', $checks['spf']['status'], 'deux SPF : invalide');
        $this->assertStringContainsString('Plusieurs', $checks['spf']['detail']);
        $this->assertSame('warn', $checks['dmarc']['status'], 'p=none : simple observation');
        $this->assertSame('warn', $checks['name']['status']);
        $this->assertStringContainsString('MAIL_FROM_NAME="Kouma"', $checks['name']['fix']);

        $permissive = collect((new MailHealth('contac@kouma.site', $this->dns(['kouma.site|TXT' => ['v=spf1 a ?all']]), 'kouma.site'))->checks())->keyBy('key');
        $this->assertSame('warn', $permissive['spf']['status']);
    }

    public function test_an_address_from_another_domain_than_the_site_is_flagged(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.name' => 'Kouma']);

        $checks = collect((new MailHealth('kaborejures@gmail.com', $this->dns([]), 'kouma.site'))->checks())->keyBy('key');
        $this->assertSame('bad', $checks['alignment']['status']);

        // Pendant un changement de domaine, un sous-domaine du même domaine reste aligné.
        $this->assertSame('ok', collect((new MailHealth('contac@mail.kouma-ai.com', $this->dns([]), 'kouma-ai.com'))->checks())->keyBy('key')['alignment']['status']);
        $this->assertSame('info', collect((new MailHealth('contac@kouma.site', $this->dns([]), 'localhost'))->checks())->keyBy('key')['alignment']['status']);
    }

    public function test_the_log_driver_is_reported_and_the_command_runs(): void
    {
        config(['mail.default' => 'log']);
        $checks = collect((new MailHealth('contac@kouma.site', $this->dns([]), 'kouma.site'))->checks())->keyBy('key');
        $this->assertSame('bad', $checks['driver']['status']);

        $this->artisan('mail:check', ['address' => 'contac@kouma.site'])->expectsOutputToContain('Adresse d\'expédition')->assertExitCode(1);
    }

    public function test_the_emails_page_shows_the_health_panel_to_the_super_admin(): void
    {
        Cache::put('mail.health', (new MailHealth('contac@kouma.site', $this->dns([]), 'kouma.site'))->checks(), 600);

        $this->actingAs($this->admin())->get(route('admin.emails.index'))->assertOk()
            ->assertSee('Arrivent-ils bien dans la boîte de réception')->assertSee('SPF')->assertSee('DKIM')->assertSee('DMARC')->assertSee('Que faire');
        $this->actingAs($this->admin())->get(route('admin.emails.index', ['verifier' => 1]))->assertOk();
    }

    /* ------------------------------------------------------------------------------------------------
       Solde Twilio
       ------------------------------------------------------------------------------------------------ */

    private function twilio(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->set('whatsapp.twilio.account_sid', 'ACsub000000000000000000000000000000');
        $settings->set('whatsapp.twilio.auth_token', 'sub-token', true);
    }

    public function test_a_subaccount_balance_error_explains_the_cause(): void
    {
        $this->twilio();
        Http::fake([
            'api.twilio.com/*/Balance.json' => Http::response(['message' => 'The requested resource was not found', 'status' => 404], 404),
            'api.twilio.com/2010-04-01/Accounts/ACsub000000000000000000000000000000.json' => Http::response(['sid' => 'ACsub000000000000000000000000000000', 'owner_account_sid' => 'ACmain00000000000000000000000000000']),
        ]);

        $result = app(TwilioWallet::class)->balance(fresh: true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sous-compte', $result['error']);
        $this->assertStringNotContainsString('HTTP 404', $result['error']);
    }

    public function test_the_main_account_credentials_can_be_used_just_to_read_the_balance(): void
    {
        $this->twilio();
        $settings = app(PlatformSettings::class);
        $settings->set('whatsapp.twilio.balance_sid', 'ACmain00000000000000000000000000000');
        $settings->set('whatsapp.twilio.balance_token', 'main-token', true);
        Http::fake(['api.twilio.com/*/Accounts/ACmain00000000000000000000000000000/Balance.json' => Http::response(['balance' => '12.3400', 'currency' => 'USD'])]);

        $result = app(TwilioWallet::class)->balance(fresh: true);

        $this->assertTrue($result['ok']);
        $this->assertSame(12.34, $result['balance']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'ACmain') && $r->hasHeader('Authorization', 'Basic '.base64_encode('ACmain00000000000000000000000000000:main-token')));
    }

    public function test_a_normal_account_still_reads_its_own_balance_and_other_errors_keep_their_message(): void
    {
        $this->twilio();
        Http::fake(['api.twilio.com/*/Balance.json' => Http::response(['balance' => '5.00', 'currency' => 'USD'])]);
        $this->assertTrue(app(TwilioWallet::class)->balance(fresh: true)['ok']);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.twilio.com/*/Balance.json' => Http::response(['message' => 'Authenticate'], 401)]);
        $this->assertStringContainsString('Twilio HTTP 401', app(TwilioWallet::class)->balance(fresh: true)['error']);
    }

    public function test_the_settings_page_accepts_the_main_account_for_the_balance(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'brand_name' => 'Kouma', 'twilio_balance_sid' => 'ACmain1', 'twilio_balance_token' => 'jeton-principal',
        ])->assertSessionHasNoErrors();

        $settings = app(PlatformSettings::class);
        $this->assertSame('ACmain1', $settings->get('whatsapp.twilio.balance_sid'));
        $this->assertSame('jeton-principal', $settings->get('whatsapp.twilio.balance_token'));
        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertDontSee('jeton-principal')->assertSee('SID du compte principal');
    }
}
