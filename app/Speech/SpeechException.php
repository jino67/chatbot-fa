<?php

namespace App\Speech;

use RuntimeException;

/**
 * Echec de la voix. `kind` : unavailable (aucun moteur configure), unsupported (langue ou format non pris en charge),
 * too_long, empty (rien d'audible), billing, auth, rate_limit, network, provider.
 */
class SpeechException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'provider')
    {
        parent::__construct($message);
    }
}
