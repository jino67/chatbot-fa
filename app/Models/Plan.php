<?php

namespace App\Models;

use App\Support\Currency;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'slug', 'audience', 'name', 'tagline', 'prices', 'period_months', 'trial_days', 'limits', 'features',
        'is_public', 'is_default', 'is_highlighted', 'sort',
    ];

    protected $attributes = ['period_months' => 1, 'audience' => 'business'];

    protected function casts(): array
    {
        return [
            'prices' => 'array',
            'limits' => 'array',
            'features' => 'array',
            'is_public' => 'boolean',
            'is_default' => 'boolean',
            'is_highlighted' => 'boolean',
        ];
    }

    /** Limites minimales appliquees si une offre a ete supprimee alors qu'un espace la reference encore. */
    public const FALLBACK_LIMITS = ['bots' => 1, 'sources' => 4, 'pages_per_crawl' => 10, 'messages_per_month' => 100, 'members' => 1, 'whatsapp_messages_per_month' => 0, 'voice_per_month' => 0];

    public static function bySlug(?string $slug): ?self
    {
        return $slug ? static::where('slug', $slug)->first() : null;
    }

    /** Offres de la page des tarifs et de l'abonnement (les offres pour developpeurs ont leur propre page). */
    public function scopeForBusiness($query)
    {
        return $query->where('audience', 'business');
    }

    public function scopeForDevelopers($query)
    {
        return $query->where('audience', 'developer');
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->orderBy('sort')->first() ?? static::orderBy('sort')->first();
    }

    /** @return list<array{key:string,label:string,type:string}> champs de quotas affiches et editables */
    public static function limitFields(): array
    {
        return [
            ['key' => 'bots', 'label' => 'Assistants', 'type' => 'int'],
            ['key' => 'sources', 'label' => 'Sources de connaissances', 'type' => 'int'],
            ['key' => 'pages_per_crawl', 'label' => 'Pages lues par site', 'type' => 'int'],
            ['key' => 'messages_per_month', 'label' => 'Réponses de l\'assistant par mois', 'type' => 'int'],
            ['key' => 'members', 'label' => 'Utilisateurs', 'type' => 'int'],
            ['key' => 'whatsapp_messages_per_month', 'label' => 'Messages WhatsApp par mois (reçus, envoyés, modèles)', 'type' => 'int'],
            ['key' => 'voice_per_month', 'label' => 'Messages vocaux par mois (écoutés ou envoyés)', 'type' => 'int'],
        ];
    }

    /** @return list<array{key:string,label:string}> options incluses ou non */
    public static function featureFields(): array
    {
        return [
            ['key' => 'whatsapp', 'label' => 'WhatsApp'],
            ['key' => 'templates', 'label' => 'Modèles de messages WhatsApp'],
            ['key' => 'remove_branding', 'label' => 'Sans publicité « Propulsé par »'],
            ['key' => 'priority_support', 'label' => 'Assistance prioritaire'],
            ['key' => 'voice', 'label' => 'Messages vocaux : écoute et réponse audio'],
            ['key' => 'chat_import', 'label' => 'Import de vos discussions WhatsApp (apprentissage du style)'],
            ['key' => 'api', 'label' => 'API pour développeurs'],
        ];
    }

    public function limit(string $key): int
    {
        return (int) ($this->limits[$key] ?? self::FALLBACK_LIMITS[$key] ?? 0);
    }

    public function feature(string $key): bool
    {
        return (bool) ($this->features[$key] ?? false);
    }

    /** Prix dans une devise, ou null si l'offre n'en propose pas dans cette devise. */
    public function priceIn(?string $currency): ?int
    {
        $price = $currency ? ($this->prices[$currency] ?? null) : null;

        return $price === null ? null : (int) $price;
    }

    /** Devise effectivement affichee : celle demandee si l'offre y a un prix, sinon la premiere devise definie. */
    public function currencyFor(?string $wanted): string
    {
        if ($this->priceIn($wanted) !== null) {
            return $wanted;
        }

        foreach (Currency::codes() as $code) {
            if ($this->priceIn($code) !== null) {
                return $code;
            }
        }

        return Currency::default();
    }

    public function isFree(): bool
    {
        return array_sum(array_map('intval', $this->prices ?? [])) === 0;
    }

    /** Une offre avec une duree d'essai (en jours) : le client y reste ce temps, puis doit choisir une offre payante. */
    public function hasTrial(): bool
    {
        return (int) $this->trial_days > 0;
    }

    /** Date de fin d'un essai qui demarre maintenant (null si l'offre n'a pas d'essai). */
    public function trialEndsAt(): ?CarbonInterface
    {
        return $this->hasTrial() ? now()->addDays((int) $this->trial_days) : null;
    }

    public function formattedPrice(?string $currency = null): string
    {
        if ($this->isFree()) {
            return 'Gratuit';
        }

        $code = $this->currencyFor($currency ?? Currency::current());

        return Currency::format($this->priceIn($code), $code);
    }

    /** Le prix dans chaque devise, deja formate : le selecteur de devise de la page s'en sert pour changer l'affichage sans recharger. */
    public function formattedPrices(): array
    {
        return collect(Currency::codes())->mapWithKeys(fn ($code) => [$code => $this->formattedPrice($code)])->all();
    }

    /** Ce que couvre le prix : « par mois », « par 3 mois », ou la duree de l'essai gratuit. */
    public function periodLabel(): string
    {
        if ($this->isFree()) {
            return $this->hasTrial() ? "pendant {$this->trial_days} jours" : '';
        }

        return $this->period_months === 1 ? 'par mois' : "par {$this->period_months} mois";
    }

    /** Tous les prix, pour l'administration : « 10 000 FCFA, 7 500 KMF, 15 €, 17 $ ». */
    public function allPrices(): string
    {
        if ($this->isFree()) {
            return 'Gratuit';
        }

        $parts = [];
        foreach (Currency::codes() as $code) {
            if (($price = $this->priceIn($code)) !== null) {
                $parts[] = Currency::format($price, $code);
            }
        }

        return implode(', ', $parts);
    }
}
