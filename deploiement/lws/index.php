<?php

/*
|--------------------------------------------------------------------------
| Point d'entrée du site chez LWS (hébergement mutualisé)
|--------------------------------------------------------------------------
|
| Le dossier du domaine sert de dossier public, et le projet Laravel complet vit dans le sous-dossier kouma/, que le
| .htaccess voisin interdit au web :
|
|   htdocs/kouma.site/
|     .htaccess   index.php   build/   widget/   favicon.ico   sw.js ...   (ce qui est public)
|     kouma/      app, vendor, storage, .env : jamais servi
|
| Ce fichier remplace public/index.php du dépôt, qui ne part pas en ligne. Il est copié tel quel par
| scripts/build-lws-package.php.
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/kouma/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/kouma/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/kouma/bootstrap/app.php';

// La racine du domaine EST le dossier public : le manifeste de build/, le widget, les icônes.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
