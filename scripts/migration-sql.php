<?php

/*
 * Écrit en SQL MySQL ce que font une ou plusieurs migrations, pour les appliquer dans phpMyAdmin quand on n'a pas d'accès SSH
 * (LWS mutualisé). Aucun serveur MySQL n'est nécessaire : Laravel écrit les requêtes sans les exécuter (mode « pretend »).
 *
 *     php scripts/migration-sql.php database/migrations/2026_10_02_000010_create_analytics_tables.php [autre migration...] > base.sql
 *
 * Le fichier produit enregistre aussi les migrations dans la table `migrations`, pour qu'un futur « php artisan migrate »
 * ne les rejoue pas. Limite : seules les migrations de schéma conviennent (create, alter). Une migration qui interroge la
 * base (Schema::hasTable, DB::table...) ou qui déplace des données doit être traduite à la main.
 */

$racine = dirname(__DIR__);
require $racine.'/vendor/autoload.php';
$app = require $racine.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fichiers = array_slice($argv, 1);
if ($fichiers === []) {
    fwrite(STDERR, "Indiquez au moins un fichier de migration.\n");
    exit(1);
}

// Une connexion MySQL jamais ouverte : la grammaire MySQL écrit les requêtes, un PDO factice répond aux rares questions de version.
config(['database.connections.mysql_sql' => [
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'kouma', 'username' => 'x', 'password' => '',
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
]]);
$connexion = Illuminate\Support\Facades\DB::connection('mysql_sql');
$factice = new PDO('sqlite::memory:');
$connexion->setPdo($factice);
$connexion->setReadPdo($factice);
$app->make('db')->setDefaultConnection('mysql_sql');

echo "-- Kouma : mise à jour de la base de données.\n";
echo "-- Avant d'importer : dans phpMyAdmin, cliquez sur le nom de votre base dans la colonne de gauche, puis sur l'onglet Importer.\n";
echo "-- Ce fichier ne s'importe qu'une fois : les tables n'existent pas encore.\n\n";

foreach ($fichiers as $fichier) {
    $nom = basename($fichier, '.php');
    $migration = require $racine.'/'.ltrim(str_replace('\\', '/', $fichier), '/');

    echo "-- {$nom}\n";
    foreach ($connexion->pretend(fn () => $migration->up()) as $requete) {
        echo rtrim($requete['query'], ';').";\n";
    }
    echo "\n";
}

echo "-- Enregistrer les migrations comme faites (pour que « php artisan migrate » ne les rejoue pas).\n";
echo "set @lot := (select coalesce(max(batch), 0) + 1 from migrations);\n";
foreach ($fichiers as $fichier) {
    echo "insert into migrations (migration, batch) values ('".basename($fichier, '.php')."', @lot);\n";
}
