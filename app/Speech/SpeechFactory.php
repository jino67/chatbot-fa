<?php

namespace App\Speech;

use App\Models\AiProvider;
use App\Services\PlatformSettings;
use App\Support\Languages;

/**
 * Construit le moteur vocal. Cle OpenAI cherchee dans l'ordre : parametre du tableau de bord (Voix), fournisseur
 * OpenAI configure pour le chat, variable d'environnement. Sans cle : voix indisponible (jamais de faux client en production).
 */
class SpeechFactory
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function make(): SpeechClient
    {
        if (config('platform.speech.driver') === 'fake') {
            return new FakeSpeech;
        }

        $cloud = new OpenAiSpeech([
            'api_key' => $this->cloudKey(),
            'base_url' => $this->settings->get('speech.base_url') ?: config('platform.speech.openai.base_url'),
            'stt_models' => array_values(array_unique(array_filter([$this->settings->get('speech.stt_model') ?: null, ...config('platform.speech.openai.stt_models')]))),
            'tts_model' => $this->settings->get('speech.tts_model') ?: config('platform.speech.openai.tts_model'),
            'label' => 'openai',
        ]);

        return new SpeechRouter($cloud, $this->local());
    }

    /** Serveur libre pour les langues locales (compatible OpenAI), s'il est configure. */
    public function local(): ?OpenAiSpeech
    {
        $url = $this->settings->get('speech.local_url') ?: config('platform.speech.local.base_url');
        if (! $url) {
            return null;
        }

        return new OpenAiSpeech([
            'api_key' => $this->settings->get('speech.local_key') ?: config('platform.speech.local.api_key'),
            'base_url' => $url,
            'stt_models' => [$this->settings->get('speech.local_model') ?: config('platform.speech.local.model')],
            'tts_model' => null,
            'label' => 'local',
            'timeout' => 90,
            'languages' => array_keys(array_filter(Languages::all(), fn ($l) => $l['stt'] === 'local')),
        ]);
    }

    public function cloudKey(): ?string
    {
        if ($key = $this->settings->get('speech.api_key')) {
            return $key;
        }

        $provider = AiProvider::query()->where('preset', 'openai')->where('enabled', true)->first();

        return $provider?->effectiveKey() ?: config('platform.ai.env_keys.openai') ?: null;
    }
}
