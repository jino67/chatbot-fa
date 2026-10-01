<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Essai d'envoi d'e-mail avec la configuration réelle (.env), et diagnostic des pannes habituelles d'un hébergement
 * mutualisé : mauvais serveur ou port, identifiants refusés, adresse d'expéditeur qui n'est pas celle de la boîte.
 */
class MailTest extends Command
{
    protected $signature = 'platform:mail-test {to? : adresse qui reçoit l\'essai (par défaut, celle de l\'expéditeur)}';

    protected $description = 'Envoie un e-mail d\'essai et explique la panne en cas d\'échec';

    public function handle(): int
    {
        $driver = (string) config('mail.default');
        $config = (array) config("mail.mailers.{$driver}");
        $from = (string) config('mail.from.address');
        $to = (string) ($this->argument('to') ?: $from);

        $this->line('Pilote : <info>'.$driver.'</info>'.($driver === 'smtp' ? ' ('.($config['host'] ?? '?').':'.($config['port'] ?? '?').', utilisateur '.($config['username'] ?: 'aucun').')' : ''));
        $this->line('Expéditeur : <info>'.$from.'</info>   Destinataire : <info>'.$to.'</info>');

        if (in_array($driver, ['log', 'array'], true)) {
            $this->warn("Le pilote « {$driver} » n'envoie rien : il écrit le message dans les journaux. Pour envoyer de vrais e-mails, mettez MAIL_MAILER=smtp dans .env.");
        }

        try {
            Mail::raw(
                "Ceci est un e-mail d'essai envoyé par ".config('app.name').".\n\nSi vous le lisez, l'envoi fonctionne : les alertes de demandes, les rappels et les réinitialisations de mot de passe partiront de cette adresse.",
                fn ($message) => $message->to($to)->subject('Essai d\'envoi : '.config('app.name'))
            );
        } catch (\Throwable $e) {
            $this->error('Échec : '.$e->getMessage());
            $this->line($this->diagnose($e->getMessage()));

            return self::FAILURE;
        }

        $this->info($driver === 'smtp' ? 'Message remis au serveur. Vérifiez la boîte de réception (et les courriers indésirables).' : 'Message traité.');

        return self::SUCCESS;
    }

    private function diagnose(string $message): string
    {
        $message = mb_strtolower($message);

        return match (true) {
            str_contains($message, 'could not be established'), str_contains($message, 'connection refused'), str_contains($message, 'timed out'), str_contains($message, 'getaddrinfo')
                => 'Piste : le serveur ou le port est faux, ou bloqué. Sur un mutualisé, le serveur sortant est souvent « mail.votredomaine » : port 465 (SSL) ou 587 (TLS). Essayez l\'autre port.',
            str_contains($message, '535'), str_contains($message, 'authenticat'), str_contains($message, 'password'), str_contains($message, 'username')
                => 'Piste : identifiants refusés. MAIL_USERNAME est l\'adresse complète de la boîte (contact@votredomaine), MAIL_PASSWORD son mot de passe, sans guillemets superflus (entourez-le de guillemets s\'il contient # ou un espace).',
            str_contains($message, 'certificate'), str_contains($message, 'ssl'), str_contains($message, 'tls')
                => 'Piste : problème de chiffrement. Essayez l\'autre port (465 ou 587) ; le nom du serveur doit être celui du certificat de l\'hébergeur.',
            str_contains($message, '550'), str_contains($message, '553'), str_contains($message, 'relay'), str_contains($message, 'sender')
                => 'Piste : l\'hébergeur refuse l\'expéditeur. MAIL_FROM_ADDRESS doit être l\'adresse de la boîte créée (ou un alias de votre domaine).',
            default => 'Piste : relisez MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD et MAIL_FROM_ADDRESS dans .env, puis `php artisan config:clear`.',
        };
    }
}
