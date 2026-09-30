<?php

namespace App\Ai;

use RuntimeException;

/**
 * Erreur d'un fournisseur de modele. `kind` decide de la bascule :
 *  - billing : credit epuise, quota depasse (le cas le plus important a detecter)
 *  - auth : cle invalide, revoquee ou sans droit
 *  - rate_limit : trop de requetes, temporaire
 *  - overloaded : fournisseur surcharge ou en panne (HTTP 5xx)
 *  - network : connexion impossible ou delai depasse
 *  - unsupported : fonction ou modele indisponible chez ce fournisseur (ex. PDF, modele retire)
 *  - bad_request : requete refusee pour une raison qui nous est propre : on ne bascule pas, on remonte l'erreur
 */
class LlmException extends RuntimeException
{
    public const FAILOVER_KINDS = ['billing', 'auth', 'rate_limit', 'overloaded', 'network', 'unsupported'];

    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
        public readonly string $kind = 'unknown',
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Faut-il essayer le fournisseur suivant de la chaine ? */
    public function shouldFailover(): bool
    {
        return in_array($this->kind, self::FAILOVER_KINDS, true) || ($this->kind === 'unknown' && $this->retryable);
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'billing' => 'Crédit épuisé ou quota dépassé',
            'auth' => 'Clé refusée',
            'rate_limit' => 'Limite de débit atteinte',
            'overloaded' => 'Fournisseur surchargé ou en panne',
            'network' => 'Connexion impossible',
            'unsupported' => 'Fonction ou modèle indisponible',
            'bad_request' => 'Requête refusée',
            default => 'Erreur',
        };
    }
}
