<?php

namespace App\Console\Commands;

use App\Support\MailHealth;
use Illuminate\Console\Command;

/**
 * Diagnostic de délivrabilité : lit la zone DNS du domaine d'expédition (SPF, DKIM, DMARC) et le nom d'expéditeur, et dit ce qui
 * fait atterrir les e-mails dans les courriers indésirables. À relancer après tout changement de domaine ou de DNS.
 */
class MailCheck extends Command
{
    protected $signature = 'mail:check {address? : adresse d\'expédition à vérifier (par défaut MAIL_FROM_ADDRESS)}';

    protected $description = 'Vérifie SPF, DKIM, DMARC et le nom d\'expéditeur du domaine d\'envoi';

    public function handle(): int
    {
        $health = new MailHealth($this->argument('address') ?: null);
        $this->line('Adresse d\'expédition : <info>'.$health->address().'</info>');

        $checks = $health->checks();
        foreach ($checks as $check) {
            $mark = match ($check['status']) {
                'ok' => '<info>[OK]</info>  ',
                'warn' => '<comment>[À VOIR]</comment>',
                'bad' => '<error>[À CORRIGER]</error>',
                default => '[INFO]',
            };
            $this->line("{$mark} {$check['label']} : {$check['detail']}");
            if ($check['fix'] && $check['status'] !== 'ok') {
                $this->line('        Que faire : '.$check['fix']);
            }
        }

        $summary = MailHealth::summary($checks);
        $this->newLine();
        $this->line("Bilan : {$summary['label']} ({$summary['score']}/100).");

        return $summary['level'] === 'bad' ? self::FAILURE : self::SUCCESS;
    }
}
