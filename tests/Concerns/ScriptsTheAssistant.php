<?php

namespace Tests\Concerns;

use App\Ai\Llm\LlmClient;
use App\Channels\WhatsApp\InboundHandler;
use App\Chat\ChatService;
use Tests\Support\ScriptedLlm;

trait ScriptsTheAssistant
{
    /**
     * Remplace le modèle de langage par un script. Les services qui gardent une référence à l'ancien sont oubliés pour
     * être reconstruits avec le nouveau.
     *
     * @param  list<string>|string  $replies
     */
    protected function scriptedAssistant(array|string $replies = 'Bien reçu.', string $vision = "CATEGORIE: autre\nRESUME: Une photo."): ScriptedLlm
    {
        $llm = new ScriptedLlm((array) $replies, $vision);

        $this->app->instance(LlmClient::class, $llm);
        $this->app->forgetInstance(ChatService::class);
        $this->app->forgetInstance(InboundHandler::class);

        foreach (['App\Chat\VisionAnalyzer', 'App\Chat\CustomerImages'] as $class) {
            if (class_exists($class)) {
                $this->app->forgetInstance($class);
            }
        }

        return $llm;
    }
}
