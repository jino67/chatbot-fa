<?php

namespace App\Support;

/** Réglages d'exécution qui n'ont de sens que sous un serveur web. */
final class Runtime
{
    /**
     * Une réponse d'IA peut prendre plusieurs secondes : la requête web reçoit plus de temps que la limite par défaut.
     * Hors du serveur web (tests, commandes, file d'attente) rien n'est changé : PHP en ligne de commande n'a pas de
     * limite, et la fixer à 120 s ferait échouer une longue suite de tests.
     */
    /**
     * Temps de lecture d'un site pour une tranche : court dans une requête web (l'hébergeur coupe vite, la page ouverte
     * enchaîne les tranches), long en ligne de commande (file d'attente, planificateur, tests).
     */
    public static function crawlSeconds(): float
    {
        return PHP_SAPI === 'cli' ? 840.0 : 18.0;
    }

    public static function allowLongRequest(int $seconds = 120): void
    {
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($seconds);
        }
    }
}
