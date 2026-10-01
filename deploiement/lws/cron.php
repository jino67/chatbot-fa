<?php

/*
|--------------------------------------------------------------------------
| Tâche planifiée pour l'hébergement mutualisé (LWS)
|--------------------------------------------------------------------------
|
| Équivaut à « php artisan schedule:run ». Il sert quand le panneau de l'hébergeur n'accepte qu'un fichier PHP à
| exécuter, sans arguments. Il est copié dans kouma/cron.php par scripts/build-lws-package.php.
|
| À planifier toutes les minutes (ou toutes les 5 minutes, si l'hébergeur ne propose pas mieux : les tâches de Kouma
| tournent toutes les 10 minutes au plus souvent).
*/

// Jamais par le web : seule la ligne de commande (la tâche CRON) l'exécute.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(__DIR__);

$_SERVER['argv'] = ['artisan', 'schedule:run'];
$_SERVER['argc'] = 2;

require __DIR__.'/artisan';
