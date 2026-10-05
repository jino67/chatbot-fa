<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\PlatformSettings;
use App\Speech\VoiceService;
use App\Support\Languages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class Bot extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'name', 'sector', 'profile', 'instructions', 'instructions_default', 'language',
        'languages', 'voice_in', 'voice_out', 'voice_style',
        'welcome_message', 'fallback_message', 'suggested_questions', 'theme', 'allowed_origins',
        'handoff_email', 'collect_contact', 'is_active',
    ];

    protected $attributes = [
        'language' => 'fr',
        'voice_in' => true,
        'voice_out' => 'mirror',
        'voice_style' => 'feminine',
        'is_active' => true,
        'collect_contact' => false,
    ];

    protected function casts(): array
    {
        return [
            'suggested_questions' => 'array',
            'languages' => 'array',
            'voice_in' => 'boolean',
            'profile' => 'array',
            'theme' => 'array',
            'allowed_origins' => 'array',
            'collect_contact' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Bot $bot) {
            $bot->public_key ??= 'pk_'.Str::lower(Str::random(24));
        });
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /** Modeles WhatsApp de l'assistant, via ses canaux (utilise aussi pour la resolution scopee des routes). */
    public function templates(): HasManyThrough
    {
        return $this->hasManyThrough(WhatsAppTemplate::class, Channel::class, 'bot_id', 'channel_id');
    }

    /** Langues parlees, langue principale en tete (jamais vide). @return list<string> */
    public function spokenLanguages(): array
    {
        return Languages::normalize((array) $this->languages, $this->language);
    }

    /**
     * Conversation libre (salutations, bavardage, culture générale) en plus de la base de connaissances. Active par
     * défaut ; le client peut la couper dans les réglages de l'assistant, auquel cas il reste cantonné à ses sources.
     * Le prompt garde les informations de l'entreprise (prix, horaires, adresses) tirées des seuls extraits.
     */
    public function allowsFreeChat(): bool
    {
        return $this->isShowcase() || (bool) $this->profile('open_chat', true);
    }

    /** L'assistant lit-il les photos envoyées par les clients ? (actif par défaut) */
    public function acceptsImages(): bool
    {
        return $this->isShowcase() ? false : (bool) $this->profile('images', true);
    }

    /** Photos des produits : « auto » (l'assistant en joint quand il présente un produit), « ask » (seulement si le client demande à voir), « off ». */
    public function photoPolicy(): string
    {
        $policy = (string) $this->profile('photos', 'auto');

        return in_array($policy, ['auto', 'ask', 'off'], true) ? $policy : 'auto';
    }

    /**
     * L'assistant de la page d'accueil de la plateforme (réglage « marketing.landing_bot_key »). Il parle de la
     * plateforme elle-même et ne peut pas être cantonné : sa conversation libre est toujours active.
     */
    /** L'assistant de la page d'accueil lui-même, ou null s'il n'est pas encore choisi dans les paramètres. */
    public static function landing(): ?self
    {
        $key = (string) app(\App\Services\PlatformSettings::class)->get('marketing.landing_bot_key');

        return $key === '' ? null : static::withoutGlobalScopes()->where('public_key', $key)->first();
    }

    public function isShowcase(): bool
    {
        $key = (string) app(\App\Services\PlatformSettings::class)->get('marketing.landing_bot_key');

        return $key !== '' && hash_equals($key, (string) $this->public_key);
    }

    public function welcome(): string
    {
        return $this->welcome_message
            ?: "Bonjour ! Je suis l'assistant de {$this->company()}. Comment puis-je vous aider ?";
    }

    public function fallback(): string
    {
        return $this->fallback_message
            ?: "Je n'ai pas cette information pour le moment. Un membre de l'équipe pourra vous répondre : laissez-nous vos coordonnées ou contactez-nous directement.";
    }

    public function company(): string
    {
        return $this->workspace?->name ?? $this->name;
    }

    public function theme(string $key, mixed $default = null): mixed
    {
        return ($this->theme ?? [])[$key] ?? $default;
    }

    public function profile(string $key, mixed $default = null): mixed
    {
        return ($this->profile ?? [])[$key] ?? $default;
    }

    /** La consigne active differe-t-elle de la derniere consigne generee ? (le client l'a modifiee) */
    public function hasCustomInstructions(): bool
    {
        return $this->instructions_default !== null && trim((string) $this->instructions) !== trim((string) $this->instructions_default);
    }

    /** Configuration exposee publiquement au widget (aucun secret). */
    public function publicConfig(): array
    {
        $brand = app(PlatformSettings::class)->brand();

        return [
            'name' => $this->name,
            'title' => $this->theme('title', $this->name),
            'welcome' => $this->welcome(),
            'suggested' => array_values(array_filter($this->suggested_questions ?? [])),
            'color' => $this->theme('color', '#2340D9'),
            'position' => $this->theme('position', 'right'),
            'language' => $this->language,
            'rtl' => Languages::isRtl($this->language),
            'whatsapp' => $this->whatsappNumber(),
            'voice' => app(VoiceService::class)->capabilities($this),
            'languages' => collect($this->spokenLanguages())->map(fn ($c) => ['code' => $c, 'name' => Languages::all()[$c]['native'], 'rtl' => Languages::isRtl($c)])->all(),
            'collect_contact' => $this->collect_contact,
            // Publicite « Propulsé par » : retirée par l'option de l'offre (Pro et Business par défaut).
            'branding' => ! ($this->workspace?->hasFeature('remove_branding') ?? false),
            'brand' => [
                'name' => $brand['name'],
                'url' => rtrim($brand['url'], '/').'/?utm_source=widget&utm_medium=chat&utm_campaign=powered_by&utm_content='.$this->public_key,
            ],
        ];
    }

    /** Numero WhatsApp actif de l'assistant (chiffres seuls, pour un lien wa.me), si l'offre inclut WhatsApp. */
    public function whatsappNumber(): ?string
    {
        if (! ($this->workspace?->hasFeature('whatsapp') ?? false)) {
            return null;
        }

        $phone = $this->channels()->withoutGlobalScopes()->where('status', Channel::ACTIVE)->whereNotNull('display_phone')->value('display_phone');
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 8 ? $digits : null;
    }

    /** Le lien public de discussion : une page légère à partager (WhatsApp, Facebook, QR code), sans rien à installer. */
    public function chatUrl(): string
    {
        return route('chat.public', $this->public_key);
    }

    /**
     * Les origines (schéma et hôte, en minuscules, sans barre finale) de la plateforme : son adresse officielle, et ses anciens
     * noms de domaine tant qu'ils sont servis (voir docs/DOMAINE.md).
     *
     * @return list<string>
     */
    private function platformOrigins(): array
    {
        $url = (string) (app(PlatformSettings::class)->brand()['url'] ?: config('app.url'));
        $parts = parse_url($url) ?: [];
        $origins = [strtolower(($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : ''))];

        foreach ((array) config('platform.legacy_hosts', []) as $host) {
            $host = strtolower(preg_replace('/^www\./', '', trim((string) $host)));
            if ($host !== '') {
                array_push($origins, 'https://'.$host, 'https://www.'.$host);
            }
        }

        return $origins;
    }
    /** Une origine est autorisee si la liste est vide (mode ouvert) ou la contient. */
    public function allowsOrigin(?string $origin): bool
    {
        $allowed = array_filter($this->allowed_origins ?? []);

        if ($allowed === [] || $origin === null || $origin === '') {
            return true;
        }

        $origin = rtrim(strtolower($origin), '/');

        // Les pages de la plateforme elle-même (lien de discussion à partager, démonstration) ne dépendent pas du site du client.
        if (in_array($origin, $this->platformOrigins(), true)) {
            return true;
        }

        foreach ($allowed as $candidate) {
            $candidate = rtrim(strtolower(trim($candidate)), '/');

            if ($candidate === $origin) {
                return true;
            }

            // Joker de sous-domaine : https://*.exemple.com
            if (str_contains($candidate, '*.')) {
                $regex = '#^'.str_replace('\*\.', '([a-z0-9-]+\.)+', preg_quote($candidate, '#')).'$#';
                if (preg_match($regex, $origin)) {
                    return true;
                }
            }
        }

        return false;
    }
}
