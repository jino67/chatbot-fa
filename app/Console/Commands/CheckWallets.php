<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\PlatformSettings;
use App\Services\TwilioWallet;
use App\Services\UsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Surveille l'argent et les volumes, chaque heure :
 *  - solde Twilio de la plateforme sous le seuil : e-mail au super admin (une fois par jour) ;
 *  - client qui a consomme 90 % de ses messages WhatsApp : e-mail au client pour qu'il recharge (une fois par mois).
 * Les messages WhatsApp sont payes par la plateforme : un solde vide, c'est tous les clients qui se taisent.
 */
class CheckWallets extends Command
{
    protected $signature = 'platform:check-wallets';

    protected $description = 'Alerte quand le solde Twilio est bas ou qu\'un client approche de son volume de messages WhatsApp';

    public function handle(TwilioWallet $wallet, UsageService $usage, PlatformSettings $settings): int
    {
        $sent = 0;

        $balance = $wallet->balance(fresh: true);
        $threshold = (float) $settings->get('wallet.alert_below', 20);

        if ($balance['ok'] && $balance['balance'] < $threshold && Cache::add('alert:wallet:'.today()->format('Ymd'), 1, 86400)) {
            $to = config('platform.admin_email') ?: $settings->get('brand.email');
            $this->send($to, 'Solde Twilio bas', sprintf("Le solde Twilio de la plateforme est de %s %s, sous le seuil de %s. Tant qu'il n'est pas rechargé, les messages WhatsApp de tous les clients risquent de ne plus partir.\n\nPage Consommation : %s", number_format($balance['balance'], 2, ',', ' '), $balance['currency'], number_format($threshold, 0, ',', ' '), route('admin.consumption.index')));
            $sent++;
        }

        Workspace::query()->each(function (Workspace $workspace) use ($usage, &$sent) {
            $allowance = $usage->whatsappAllowance($workspace);
            if ($allowance <= 0) {
                return;
            }

            $used = $usage->whatsappUsed($workspace);
            $key = 'alert:wa90:'.$workspace->id.':'.now()->format('Ym');

            if ($used >= $allowance * 0.9 && $workspace->wa_credit <= 0 && Cache::add($key, 1, now()->endOfMonth())) {
                $this->send($workspace->owner()?->email, 'Vos messages WhatsApp arrivent à leur limite', sprintf("Vous avez utilisé %d messages WhatsApp sur %d inclus dans votre offre ce mois-ci. Passé ce volume, votre assistant ne pourra plus répondre sur WhatsApp. Rechargez des messages (Mobile Money accepté) ou changez d'offre depuis la page Abonnement.\n\nEspace : %s", $used, $allowance, $workspace->name));
                $sent++;
            }
        });

        $this->info("Alertes envoyées : {$sent}.");

        return self::SUCCESS;
    }

    private function send(?string $to, string $subject, string $body): void
    {
        if (! $to) {
            return;
        }

        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
