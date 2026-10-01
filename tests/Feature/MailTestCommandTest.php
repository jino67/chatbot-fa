<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Mailer;
use Tests\TestCase;

/** Commande d'essai des e-mails, pour valider la boîte du domaine avant la mise en ligne. */
class MailTestCommandTest extends TestCase
{
    public function test_the_command_sends_a_test_message_and_says_which_driver_is_used(): void
    {
        config(['mail.from.address' => 'contact@kouma.site']);

        $this->artisan('platform:mail-test', ['to' => 'moi@exemple.test'])
            ->expectsOutputToContain('Pilote : array')
            ->expectsOutputToContain('contact@kouma.site')
            ->expectsOutputToContain('n\'envoie rien')
            ->assertSuccessful();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('moi@exemple.test', $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringContainsString('Essai d\'envoi', $messages[0]->getOriginalMessage()->getSubject());
    }

    /** Un envoi qui échoue avec ce message : Mail::to(...)->send(...) lève l'erreur du serveur de courrier. */
    private function mailFailsWith(string $message): void
    {
        $pending = \Mockery::mock();
        $pending->shouldReceive('send')->once()->andThrow(new \RuntimeException($message));
        Mail::shouldReceive('to')->once()->andReturn($pending);
    }

    public function test_a_failure_is_explained_with_the_likely_cause(): void
    {
        $this->mailFailsWith('Authentication failed: 535 Incorrect authentication data');

        $this->artisan('platform:mail-test')
            ->expectsOutputToContain('Échec')
            ->expectsOutputToContain('identifiants refusés')
            ->assertFailed();
    }

    public function test_a_connection_failure_points_to_the_server_and_port(): void
    {
        $this->mailFailsWith('Connection could not be established with host "mail.kouma.site:465"');

        $this->artisan('platform:mail-test', ['to' => 'a@b.test'])
            ->expectsOutputToContain('465 (SSL) ou 587')
            ->assertFailed();
    }

    public function test_the_test_message_uses_the_branded_template_with_a_text_version(): void
    {
        $this->artisan('platform:mail-test', ['to' => 'moi@exemple.test'])->assertSuccessful();

        $message = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();

        $this->assertStringContainsString('Tout droit de Kouma', $message->getHtmlBody());
        $this->assertStringContainsString('d&#039;e-mails fonctionne', $message->getHtmlBody());
        $this->assertStringContainsString('email-mark.png', $message->getHtmlBody(), 'le bandeau de la marque');
        $this->assertStringContainsString('Tout droit de Kouma', $message->getTextBody(), 'la version texte existe aussi');
    }
}
