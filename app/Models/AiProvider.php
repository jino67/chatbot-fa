<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un fournisseur de modele de langage dans la chaine de bascule (voir App\Ai\Llm\LlmRouter).
 * La cle d'API est chiffree ; si elle est vide, la cle de l'environnement du meme preset est utilisee.
 */
class AiProvider extends Model
{
    public const DRIVER_ANTHROPIC = 'anthropic';

    public const DRIVER_OPENAI = 'openai_compatible';

    /** Modeles predefinis : URL de base et modele proposes a la creation. Prix indicatifs, a verifier. */
    public const PRESETS = [
        'anthropic' => ['label' => 'Anthropic (Claude)', 'driver' => self::DRIVER_ANTHROPIC, 'base_url' => null, 'model' => 'claude-haiku-4-5', 'vision' => true, 'price_in' => 1.0, 'price_out' => 5.0],
        'openai' => ['label' => 'OpenAI', 'driver' => self::DRIVER_OPENAI, 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini', 'vision' => true, 'price_in' => 0.15, 'price_out' => 0.6],
        'openrouter' => ['label' => 'Llama via OpenRouter', 'driver' => self::DRIVER_OPENAI, 'base_url' => 'https://openrouter.ai/api/v1', 'model' => 'meta-llama/llama-3.3-70b-instruct', 'vision' => false, 'price_in' => 0.10, 'price_out' => 0.32],
        'deepinfra' => ['label' => 'Llama via DeepInfra', 'driver' => self::DRIVER_OPENAI, 'base_url' => 'https://api.deepinfra.com/v1/openai', 'model' => 'meta-llama/Llama-3.3-70B-Instruct', 'vision' => false, 'price_in' => 0.23, 'price_out' => 0.40],
        'together' => ['label' => 'Llama via Together AI', 'driver' => self::DRIVER_OPENAI, 'base_url' => 'https://api.together.xyz/v1', 'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo', 'vision' => false, 'price_in' => 0.88, 'price_out' => 0.88],
        'ollama' => ['label' => 'Llama sur votre serveur (Ollama)', 'driver' => self::DRIVER_OPENAI, 'base_url' => 'http://localhost:11434/v1', 'model' => 'llama3.2', 'vision' => false, 'price_in' => 0.0, 'price_out' => 0.0],
        'custom' => ['label' => 'Autre fournisseur compatible OpenAI', 'driver' => self::DRIVER_OPENAI, 'base_url' => '', 'model' => '', 'vision' => false, 'price_in' => null, 'price_out' => null],
    ];

    protected $fillable = [
        'name', 'driver', 'preset', 'api_key', 'base_url', 'model', 'vision_model', 'supports_vision',
        'price_in', 'price_out', 'priority', 'enabled',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'supports_vision' => 'boolean',
            'enabled' => 'boolean',
            'disabled_until' => 'datetime',
            'last_error_at' => 'datetime',
            'last_success_at' => 'datetime',
            'price_in' => 'float',
            'price_out' => 'float',
        ];
    }

    /** Cle effective : celle saisie dans le tableau de bord, sinon celle de l'environnement. */
    public function effectiveKey(): ?string
    {
        if ($this->api_key) {
            return $this->api_key;
        }

        return config('platform.ai.env_keys.'.$this->preset) ?: null;
    }

    /** Les serveurs locaux (Ollama) fonctionnent sans cle. */
    public function isConfigured(): bool
    {
        return $this->preset === 'ollama' || filled($this->effectiveKey());
    }

    public function isCoolingDown(): bool
    {
        return $this->disabled_until !== null && $this->disabled_until->isFuture();
    }

    /** Peut etre essaye maintenant. */
    public function isAvailable(): bool
    {
        return $this->enabled && $this->isConfigured() && ! $this->isCoolingDown();
    }

    public function statusLabel(): string
    {
        return match (true) {
            ! $this->enabled => 'Désactivé',
            ! $this->isConfigured() => 'Clé manquante',
            $this->isCoolingDown() => 'En pause',
            $this->status === 'ok' => 'Opérationnel',
            $this->status === 'down' => 'En panne',
            default => 'Non testé',
        };
    }

    public function statusTone(): string
    {
        return match (true) {
            ! $this->enabled || ! $this->isConfigured() => 'gray',
            $this->isCoolingDown() || $this->status === 'down' => 'red',
            $this->status === 'ok' => 'green',
            default => 'amber',
        };
    }

    public function keyHint(): string
    {
        $key = $this->effectiveKey();

        if (! $key) {
            return $this->preset === 'ollama' ? 'aucune clé nécessaire' : 'aucune clé';
        }

        return ($this->api_key ? 'saisie' : 'environnement').' : ••••'.substr($key, -4);
    }

    /** Cout estime en USD d'une reponse moyenne (2 800 jetons en entree, 350 en sortie). */
    public function estimatedCostPerAnswer(): ?float
    {
        if ($this->price_in === null || $this->price_out === null) {
            return null;
        }

        return (2800 * $this->price_in + 350 * $this->price_out) / 1_000_000;
    }
}
