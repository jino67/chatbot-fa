<?php

namespace App\Ai\Llm;

use App\Ai\LlmException;

interface LlmClient
{
    /** Identifiant du fournisseur, stocke dans les meta des messages (analytique, debug). */
    public function name(): string;

    /** @throws LlmException */
    public function complete(LlmRequest $request): LlmResponse;

    /**
     * Lit une image ou un PDF scanne et renvoie son contenu en texte (OCR + description).
     *
     * @throws LlmException
     */
    public function transcribe(string $binary, string $mimeType, string $instruction): string;
}
