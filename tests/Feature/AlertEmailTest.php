<?php

namespace Tests\Feature;

use App\Mail\Notice;
use App\Models\User;
use App\Notify\Events;
use App\Services\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** L'adresse qui reçoit les alertes de l'équipe est privée : elle n'est jamais l'e-mail de contact affiché partout. */
class AlertEmailTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_private_alert_address_wins_then_the_environment_then_the_public_contact(): void
    {
        $settings = app(PlatformSettings::class);
        config(['platform.admin_email' => null]);
        $this->assertNull($settings->alertEmail());

        $settings->set('brand.email', 'contact@kouma.example');
        $this->assertSame('contact@kouma.example', $settings->alertEmail(), 'repli : l\'ancienne adresse unique');

        config(['platform.admin_email' => 'direction@kouma.example']);
        $this->assertSame('direction@kouma.example', $settings->alertEmail());

        $settings->set('team.alert_email', 'moi@exemple.test');
        $this->assertSame('moi@exemple.test', $settings->alertEmail());
    }

    public function test_an_invalid_alert_address_is_ignored(): void
    {
        $settings = app(PlatformSettings::class);
        config(['platform.admin_email' => null]);
        $settings->set('team.alert_email', 'pas-une-adresse');
        $settings->set('brand.email', 'contact@kouma.example');

        $this->assertSame('contact@kouma.example', $settings->alertEmail());
    }

    public function test_team_alerts_go_to_the_private_address_and_the_public_contact_never_receives_them(): void
    {
        Mail::fake();
        $settings = app(PlatformSettings::class);
        $settings->set('brand.email', 'contact@kouma.example');
        $settings->set('team.alert_email', 'moi@exemple.test');

        app(Events::class)->walletLow(12.5, 'USD', 20.0);

        Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo('moi@exemple.test') && ! $mail->hasTo('contact@kouma.example'));
    }

    public function test_the_weekly_digest_goes_to_the_private_address(): void
    {
        Mail::fake();
        $settings = app(PlatformSettings::class);
        $settings->set('brand.email', 'contact@kouma.example');
        $settings->set('team.alert_email', 'moi@exemple.test');

        $this->artisan('analytics:digest', ['--force' => true])->assertSuccessful();

        Mail::assertSent(\App\Mail\AnalyticsDigest::class, fn ($mail) => $mail->hasTo('moi@exemple.test'));
    }

    public function test_the_private_address_never_appears_on_public_pages_or_in_email_footers(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->set('brand.email', 'contact@kouma.example');
        $settings->set('team.alert_email', 'prive@exemple.test');

        $this->get('/')->assertOk()->assertSee('contact@kouma.example')->assertDontSee('prive@exemple.test');
        $this->get(route('legal.privacy'))->assertOk()->assertDontSee('prive@exemple.test');

        $html = (new Notice(subjectLine: 'Essai', heading: 'Essai'))->render();
        $this->assertStringContainsString('contact@kouma.example', $html);
        $this->assertStringNotContainsString('prive@exemple.test', $html);
    }

    public function test_the_settings_page_saves_both_addresses_with_honest_labels(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertOk()
            ->assertSee('E-mail de contact (public)')->assertSee('E-mail de réception des alertes de l', false)->assertSee('Jamais affiché');

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'brand_name' => 'Kouma', 'brand_email' => 'contact@kouma.example', 'team_alert_email' => 'moi@exemple.test',
        ])->assertSessionHas('status');

        $settings = app(PlatformSettings::class);
        $this->assertSame('contact@kouma.example', $settings->get('brand.email'));
        $this->assertSame('moi@exemple.test', $settings->get('team.alert_email'));

        $this->actingAs($admin)->put(route('admin.settings.update'), ['brand_name' => 'Kouma', 'brand_email' => 'contact@kouma.example', 'team_alert_email' => 'pas-valide'])->assertSessionHasErrors('team_alert_email');
    }

    public function test_the_alert_address_page_is_closed_to_clients(): void
    {
        [, $client] = $this->tenant();

        $this->actingAs($client)->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs($this->staff(User::ADMIN))->get(route('admin.settings.edit'))->assertForbidden();
    }
}
