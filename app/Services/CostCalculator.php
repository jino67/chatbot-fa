<?php

namespace App\Services;

use App\Models\AiProvider;

/**
 * Cout estime de chaque unite de consommation, en dollars. Les tarifs unitaires se reglent dans l'administration
 * (Parametres, WhatsApp et couts) et retombent sur les valeurs de config/platform.php.
 *
 * Message WhatsApp = frais de l'intermediaire (Twilio : 0,005 $ par message entrant et sortant, Meta direct : aucun)
 * + frais Meta selon la categorie (service, utilitaire, marketing...). Le fournisseur le moins cher est donc Meta direct.
 */
class CostCalculator
{
    /** @var array<string,AiProvider>|null */
    private ?array $providers = null;

    public function __construct(private readonly PlatformSettings $settings) {}

    /** @param  string  $provider  meta | twilio  @param  string  $kind  in | out | template */
    public function whatsapp(string $provider, string $kind, ?string $category = null): float
    {
        $intermediary = $provider === 'twilio' ? $this->twilioFee() : 0.0;

        $meta = match ($kind) {
            'template' => $this->metaRate(strtolower($category ?: 'utility')),
            'out' => $this->metaRate('service'),
            default => 0.0,
        };

        return round($intermediary + $meta, 6);
    }

    public function twilioFee(): float
    {
        return (float) $this->settings->get('costs.twilio_fee', config('platform.costs.whatsapp.twilio_fee'));
    }

    public function metaRate(string $category): float
    {
        $default = config('platform.costs.whatsapp.meta.'.$category, config('platform.costs.whatsapp.meta.utility'));

        return (float) $this->settings->get('costs.meta_'.$category, $default);
    }

    public function ai(?string $provider, ?string $model, int $tokensIn, int $tokensOut): float
    {
        if (in_array($provider, [null, '', 'fake'], true)) {
            return 0.0;
        }

        $row = $this->providers()[$provider] ?? null;
        $priceIn = (float) ($row?->price_in ?? config('platform.costs.ai_default.in'));
        $priceOut = (float) ($row?->price_out ?? config('platform.costs.ai_default.out'));

        return round(($tokensIn * $priceIn + $tokensOut * $priceOut) / 1_000_000, 6);
    }

    /** @param  string  $kind  stt | tts */
    public function voice(string $kind, int $seconds): float
    {
        $perMinute = (float) $this->settings->get('costs.'.$kind.'_minute', config('platform.costs.voice.'.$kind.'_per_minute'));

        return round($perMinute * $seconds / 60, 6);
    }

    /** Unites de la devise pour un euro (FCFA et franc comorien : parites fixes ; dollar et dirham : reglables). */
    public function rate(string $code): float
    {
        $default = (float) config('platform.costs.rates.'.$code, 1);

        return in_array($code, ['USD', 'MAD'], true)
            ? (float) $this->settings->get('costs.rate_'.strtolower($code), $default)
            : $default;
    }

    public function usdTo(float $usd, string $code): float
    {
        return $usd / $this->rate('USD') * $this->rate($code);
    }

    public function toUsd(float $amount, string $code): float
    {
        return $amount / $this->rate($code) * $this->rate('USD');
    }

    /** @return array<string,AiProvider> */
    private function providers(): array
    {
        return $this->providers ??= AiProvider::all()->keyBy('preset')->all();
    }
}
