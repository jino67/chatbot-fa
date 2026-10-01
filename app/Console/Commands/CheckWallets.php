<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Notify\Events;
use App\Services\PlatformSettings;
use App\Services\TwilioWallet;
use App\Services\UsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Surveille l'argent et les volumes, chaque heure :
 *  - solde Twilio de la plateforme sous le seuil : alerte à l'équipe, téléphone et e-mail (une fois par jour) ;
 *  - client qui a consomme 90 % de ses messages WhatsApp : alerte au client pour qu'il recharge (une fois par mois).
 * Les messages WhatsApp sont payes par la plateforme : un solde vide, c'est tous les clients qui se taisent.
 */
class CheckWallets extends Command
{
    protected $signature = 'platform:check-wallets';

    protected $description = 'Alerte quand le solde Twilio est bas ou qu\'un client approche de son volume de messages WhatsApp';

    public function handle(TwilioWallet $wallet, UsageService $usage, PlatformSettings $settings, Events $events): int
    {
        $sent = 0;

        $balance = $wallet->balance(fresh: true);
        $threshold = (float) $settings->get('wallet.alert_below', 20);

        if ($balance['ok'] && $balance['balance'] < $threshold && Cache::add('alert:wallet:'.today()->format('Ymd'), 1, 86400)) {
            $events->walletLow((float) $balance['balance'], (string) $balance['currency'], $threshold);
            $sent++;
        }

        Workspace::query()->each(function (Workspace $workspace) use ($usage, $events, &$sent) {
            $allowance = $usage->whatsappAllowance($workspace);
            if ($allowance <= 0) {
                return;
            }

            $used = $usage->whatsappUsed($workspace);
            $key = 'alert:wa90:'.$workspace->id.':'.now()->format('Ym');

            if ($used >= $allowance * 0.9 && $workspace->wa_credit <= 0 && Cache::add($key, 1, now()->endOfMonth())) {
                $events->whatsappNearLimit($workspace, $used, $allowance);
                $sent++;
            }
        });

        $this->info("Alertes envoyées : {$sent}.");

        return self::SUCCESS;
    }
}
