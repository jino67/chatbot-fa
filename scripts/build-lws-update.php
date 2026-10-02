<?php

/*
 * Mise à jour d'un site déjà en ligne chez LWS, sans reconstruire tout le paquet : un petit zip qui ne contient que les
 * fichiers modifiés depuis le dernier commit (plus build/ quand le CSS ou le JS a changé).
 *
 *     php scripts/build-lws-update.php [nom-du-zip] [dossier-de-sortie] [--depuis=<commit>]
 *
 * Sans --depuis : les fichiers modifiés depuis le dernier commit. Avec --depuis=<commit> : ceux qui diffèrent de ce commit (le dernier
 * commit réellement mis en ligne), commits suivants compris. À utiliser quand on a commité entre deux mises à jour.
 *
 * Par défaut : ../kouma-lws/mise-a-jour.zip. À extraire à la racine du dossier du domaine kouma.site, en écrasant :
 * les fichiers du projet vont dans kouma/, ceux de public/ à la racine. Le .env, storage/ et vendor/ ne sont jamais touchés.
 *
 * Ne contient AUCUN secret (ni .env, ni mot de passe), mais il se supprime du serveur une fois extrait, comme tout zip.
 *
 * Les migrations de base de données ne s'appliquent pas toutes seules (pas d'accès SSH) : quand une migration a changé, le script
 * écrit à côté du zip un fichier « <nom>-base-de-donnees.sql » (voir scripts/migration-sql.php) à importer dans phpMyAdmin.
 */

$racine = dirname(__DIR__);
chdir($racine);

// Les options (--depuis=...) se lisent à part ; le reste est positionnel.
$depuis = null;
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--depuis=')) {
        $depuis = substr($arg, 9);
    } else {
        $args[] = $arg;
    }
}

$nom = $args[0] ?? 'mise-a-jour.zip';
$sortie = $args[1] ?? dirname($racine).'/kouma-lws';
@mkdir($sortie, 0777, true);

// Fichiers modifiés ou nouveaux (suivis ou non), depuis le dernier commit ou depuis le commit indiqué.
$fichiers = [];
if ($depuis !== null) {
    if (! preg_match('/^[0-9a-f]{4,40}$|^[A-Za-z0-9._\/-]+$/', $depuis)) {
        fwrite(STDERR, "--depuis attend un commit ou une branche.\n");
        exit(1);
    }
    $differents = [];
    exec('git diff --name-only --diff-filter=ACMR '.escapeshellarg($depuis), $differents, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Commit introuvable : {$depuis}\n");
        exit(1);
    }
    $nouveaux = [];
    exec('git ls-files --others --exclude-standard', $nouveaux);
    foreach (array_merge($differents, $nouveaux) as $chemin) {
        if (is_file($chemin)) {
            $fichiers[] = str_replace('\\', '/', trim($chemin, '"'));
        }
    }
    $fichiers = array_values(array_unique($fichiers));
} else {
    $lignes = [];
    exec('git status --porcelain --untracked-files=all', $lignes);
    foreach ($lignes as $ligne) {
        $statut = substr($ligne, 0, 2);
        $chemin = trim(substr($ligne, 3));
        if (str_contains($chemin, ' -> ')) {
            $chemin = substr($chemin, strpos($chemin, ' -> ') + 4);
        }
        if (str_contains($statut, 'D') || ! is_file($chemin)) {
            continue;
        }
        $fichiers[] = str_replace('\\', '/', trim($chemin, '"'));
    }
}

// Ce qui ne part jamais en ligne : tests, docs, scripts, outils de construction, secrets.
$exclus = '#^(tests/|docs/|scripts/|deploiement/|node_modules/|\.git|\.env|README|CLAUDE\.md|phpunit|package|vite\.config|composer\.(json|lock)$|storage/|vendor/)#';
$retenus = array_values(array_filter($fichiers, fn ($f) => ! preg_match($exclus, $f)));

// Les migrations ne s'appliquent pas toutes seules (pas de SSH) : on en écrit le SQL MySQL à importer dans phpMyAdmin.
$migrations = array_values(array_filter($retenus, fn ($f) => str_starts_with($f, 'database/migrations/')));
$fichierSql = null;
if ($migrations !== []) {
    sort($migrations);
    $fichierSql = rtrim($sortie, '/').'/'.preg_replace('/\.zip$/', '', $nom).'-base-de-donnees.sql';
    $commande = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/migration-sql.php').' '.implode(' ', array_map('escapeshellarg', $migrations));
    exec($commande.' 2>&1', $sql, $code);
    if ($code !== 0 || ! str_contains(implode("\n", $sql), 'create table') && ! str_contains(implode("\n", $sql), 'alter table')) {
        fwrite(STDERR, "ARRÊT : impossible d'écrire le SQL des migrations.\n".implode("\n", $sql)."\n");
        exit(1);
    }
    file_put_contents($fichierSql, implode("\n", $sql)."\n");
}

// build/ : le manifeste et les fichiers qu'il référence, toujours inclus (petit, et toujours cohérent).
$build = [];
if (is_file('public/build/manifest.json')) {
    $build[] = 'public/build/manifest.json';
    foreach (json_decode(file_get_contents('public/build/manifest.json'), true) ?: [] as $entree) {
        foreach (array_merge([$entree['file'] ?? null], $entree['css'] ?? [], $entree['assets'] ?? []) as $f) {
            if ($f && is_file('public/build/'.$f)) {
                $build[] = 'public/build/'.$f;
            }
        }
    }
}

// La tâche planifiée de secours vit dans deploiement/lws/ et se pose dans kouma/cron.php (toujours incluse : inoffensive).
$retenus[] = 'deploiement/lws/cron.php';
$destination = fn (string $f) => match (true) {
    $f === 'deploiement/lws/cron.php' => 'kouma/cron.php',
    str_starts_with($f, 'public/') => substr($f, 7),
    default => 'kouma/'.$f,
};

$zip = new ZipArchive;
$chemin = rtrim($sortie, '/').'/'.$nom;
@unlink($chemin);
if ($zip->open($chemin, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Impossible de créer {$chemin}\n");
    exit(1);
}

$liste = [];
foreach (array_unique(array_merge($retenus, $build)) as $f) {
    $nomDansZip = $destination($f);
    $zip->addFile($f, $nomDansZip);
    $zip->setExternalAttributesName($nomDansZip, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    $liste[] = $nomDansZip;
}
$zip->close();

echo count($liste)." fichiers dans {$chemin}\n";
foreach ($liste as $f) {
    echo "  {$f}\n";
}

if ($fichierSql) {
    echo "\nBASE DE DONNÉES : ".count($migrations)." migration(s) à appliquer. Importez {$fichierSql} dans phpMyAdmin\n"
        ."(cliquez d'abord sur votre base, puis Importer) AVANT d'ouvrir le site mis à jour. Ce fichier contient la structure, jamais de mot de passe.\n";
}
