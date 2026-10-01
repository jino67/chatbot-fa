<?php

namespace App\Speech;

use RuntimeException;

/**
 * Le message vocal n'a pas pu etre traite. `reason` : disabled (offre sans voix ou reglage coupe), quota, engine
 * (aucun moteur pour cette langue), too_long, unclear (rien d'audible ou panne du moteur).
 */
class VoiceRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $reason);
    }
}
