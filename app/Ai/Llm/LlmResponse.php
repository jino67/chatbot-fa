<?php

namespace App\Ai\Llm;

final class LlmResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheReadTokens = 0,
        public readonly string $stopReason = 'end_turn',
        public readonly bool $refused = false,
        public readonly ?string $model = null,
        public readonly ?string $provider = null,
    ) {}

    /** Copie annotee du fournisseur qui a effectivement repondu (utile apres une bascule). */
    public function withProvider(string $provider): self
    {
        return new self($this->text, $this->inputTokens, $this->outputTokens, $this->cacheReadTokens, $this->stopReason, $this->refused, $this->model, $provider);
    }
}
