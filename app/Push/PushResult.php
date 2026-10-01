<?php

namespace App\Push;

/** Ce que le service de notification a répondu à un envoi. */
final class PushResult
{
    public const OK = 'ok';

    /** L'abonnement n'existe plus (404, 410) : il faut l'oublier. */
    public const GONE = 'gone';

    /** Échec passager (limite atteinte, service indisponible, réseau) : on réessaiera plus tard. */
    public const RETRY = 'retry';

    /** Refus définitif autre que « disparu » (message invalide, clé refusée). */
    public const ERROR = 'error';

    public function __construct(public readonly string $status, public readonly int $code = 0, public readonly string $detail = '') {}

    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    public function gone(): bool
    {
        return $this->status === self::GONE;
    }
}
