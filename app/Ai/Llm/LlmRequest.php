<?php

namespace App\Ai\Llm;

final class LlmRequest
{
    /**
     * @param  list<array{role:string, content:string|array}>  $messages  format Messages API (user / assistant)
     */
    public function __construct(
        public readonly string $system,
        public readonly array $messages,
        public readonly ?int $maxTokens = null,
        public readonly ?string $model = null,
    ) {}
}
