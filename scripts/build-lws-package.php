<?php

/*
 * Construit le paquet de mise en ligne chez LWS (hébergement mutualisé), sur le modèle d'Amical Clinic.
 *
 *     KOUMA_DB_PASSWORD=... [MAIL_PASSWORD_PRODUCTION=...] php scripts/build-lws-package.php [dossier-de-sortie]
 *
 * Sortie (par défaut : ../kouma-lws, hors du dépôt) :
 *     kouma.site-lws.zip      à décompresser à la racine du dossier du domaine kouma.site
 *     kouma-base.sql          à importer une fois dans phpMyAdmin (base MySQL de LWS)
 *     identifiants-admin.txt  compte administrateur créé et son mot de passe
 *     LISEZ-MOI.txt           la marche à suivre
 *
 * Le zip contient, à sa racine, exactement ce que le dossier du domaine doit contenir :
 *     .htaccess, index.php            depuis deploiement/lws/
 *     build/, widget/, icônes, sw.js  depuis public/
 *     kouma/                          le projet : app, vendor de production, .env de production
 *
 * Avant de zipper, le script monte le site exactement comme le serveur le fera (serveur intégré de PHP, base MySQL
 * temporaire où le .sql est importé), puis vérifie les pages, la connexion de l'administrateur et une vraie réponse.
 *
 * LE ZIP ET identifiants-admin.txt CONTIENNENT DES SECRETS (clé de l'application, mot de passe de la base, clés d'IA
 * copiées du .env local, mot de passe de la boîte e-mail). Ils sont écrits HORS du dépôt et ne doivent jamais y entrer.
 * Les mots de passe se passent par variables d'environnement : aucun n'est écrit dans ce fichier.
 */

const PROJET = 'kouma';
const DOMAINE = 'kouma.site';
const BOITE = 'contac@kouma.site';

// Chemins en barres obliques : la commande « source » de mariadb lit les antislashs comme des échappements.
$racine = str_replace('\\', '/', dirname(__DIR__));
$sortie = str_replace('\\', '/', rtrim($argv[1] ?? (dirname($racine).'/kouma-lws'), '\\/'));

$cfg = [
    'db_hote' => getenv('KOUMA_DB_HOST') ?: '127.0.0.1',
    'db_nom' => getenv('KOUMA_DB_NAME') ?: 'sinus1658531_2dgjay',
    'db_utilisateur' => getenv('KOUMA_DB_USER') ?: 'sinus1658531_2dgjay',
    'db_mot_de_passe' => (string) getenv('KOUMA_DB_PASSWORD'),
    'boite_mot_de_passe' => (string) getenv('MAIL_PASSWORD_PRODUCTION'),
    // Base temporaire qui sert à fabriquer et à essayer le .sql (jamais celle de LWS, inaccessible d'ici).
    'tmp_port' => (int) (getenv('KOUMA_BUILD_DB_PORT') ?: 3399),
    'mariadb' => rtrim(getenv('MARIADB_BIN') ?: 'C:/wamp64/bin/mariadb/mariadb11.3.2/bin', '\\/'),
    'composer' => getenv('COMPOSER_PHAR') ?: 'C:/composer/composer.phar',
];

const DOSSIERS_PROJET = ['app', 'bootstrap', 'config', 'database', 'lang', 'resources', 'routes'];
const FICHIERS_PROJET = ['artisan', 'composer.json', 'composer.lock'];
const EXCLUS_NOMS = ['.git', 'node_modules', '.claude', 'CLAUDE.md', 'AGENTS.md', '.DS_Store', 'Thumbs.db', '.phpunit.cache'];
const EXCLUS_EXTENSIONS = ['sqlite', 'sqlite-journal', 'log'];
// Sources du front : le site ne lit que build/ (compilé), jamais ces dossiers.
const EXCLUS_CHEMINS = ['resources/js', 'resources/css'];
const PUBLIC_EXCLUS = ['index.php', '.htaccess', 'hot', 'storage', 'fonts-manifest.dev.json'];
const SQUELETTE_STORAGE = ['app', 'app/private', 'app/public', 'framework', 'framework/cache', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'framework/views', 'logs'];

/* ================================== Outils ================================== */

function etape(string $texte): void
{
    echo "\n== {$texte}\n";
}

/** Lève une exception : exit() sauterait le bloc finally (serveur, base et dossier temporaire resteraient en place). */
function echec(string $message): never
{
    throw new RuntimeException($message);
}

/** @param list<string> $commande @param array<string,string> $env surcharges d'environnement */
function executer(array $commande, string $cwd, array $env = [], bool $obligatoire = true, ?string $versFichier = null): string
{
    $descripteurs = [0 => ['pipe', 'r'], 1 => $versFichier ? ['file', $versFichier, 'w'] : ['pipe', 'w'], 2 => ['pipe', 'w']];
    $processus = proc_open($commande, $descripteurs, $tubes, $cwd, array_merge(getenv(), $env));
    if (! is_resource($processus)) {
        echec('impossible de lancer '.$commande[0]);
    }
    fclose($tubes[0]);
    $out = $versFichier ? '' : stream_get_contents($tubes[1]);
    $err = stream_get_contents($tubes[2]);
    $code = proc_close($processus);
    if ($obligatoire && $code !== 0) {
        echec(basename($commande[0]).' '.implode(' ', array_slice($commande, 1, 3))." (code {$code})\n".trim($out."\n".$err));
    }

    return $out.$err;
}

function copier_arbre(string $source, string $cible, string $relatif = ''): void
{
    if (! is_dir($cible)) {
        mkdir($cible, 0777, true);
    }
    foreach (scandir($source) as $nom) {
        if ($nom === '.' || $nom === '..' || in_array($nom, EXCLUS_NOMS, true)) {
            continue;
        }
        $chemin = $relatif === '' ? $nom : $relatif.'/'.$nom;
        $de = $source.'/'.$nom;
        if (is_dir($de)) {
            if (! in_array($chemin, EXCLUS_CHEMINS, true)) {
                copier_arbre($de, $cible.'/'.$nom, $chemin);
            }
        } elseif (! in_array(strtolower(pathinfo($nom, PATHINFO_EXTENSION)), EXCLUS_EXTENSIONS, true)) {
            copy($de, $cible.'/'.$nom);
        }
    }
}

function supprimer_arbre(string $dossier): void
{
    if (! is_dir($dossier)) {
        return;
    }
    foreach (scandir($dossier) as $nom) {
        if ($nom === '.' || $nom === '..') {
            continue;
        }
        $chemin = $dossier.'/'.$nom;
        if (is_dir($chemin) && ! is_link($chemin)) {
            supprimer_arbre($chemin);
        } else {
            @chmod($chemin, 0666);
            @unlink($chemin);
        }
    }
    @rmdir($dossier);
}

function vider_dossier(string $dossier, array $garder = ['.gitignore']): void
{
    if (! is_dir($dossier)) {
        return;
    }
    foreach (scandir($dossier) as $nom) {
        if ($nom === '.' || $nom === '..' || in_array($nom, $garder, true)) {
            continue;
        }
        is_dir($dossier.'/'.$nom) ? supprimer_arbre($dossier.'/'.$nom) : @unlink($dossier.'/'.$nom);
    }
}

function lire_env_local(string $racine, string $cle): string
{
    $chemin = $racine.'/.env';
    if (! is_file($chemin)) {
        return '';
    }
    foreach (file($chemin, FILE_IGNORE_NEW_LINES) as $ligne) {
        if (str_starts_with($ligne, $cle.'=')) {
            return trim(substr($ligne, strlen($cle) + 1), " \t\"'");
        }
    }

    return '';
}

function mot_de_passe_lisible(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $groupes = [];
    for ($g = 0; $g < 4; $g++) {
        $groupe = '';
        for ($i = 0; $i < 5; $i++) {
            $groupe .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $groupes[] = $groupe;
    }

    return implode('-', $groupes);
}

/* ============================= Base MySQL temporaire ============================= */

function pdo_temporaire(array $cfg, ?string $base = null): ?PDO
{
    try {
        return new PDO("mysql:host=127.0.0.1;port={$cfg['tmp_port']}".($base ? ";dbname={$base}" : '').';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable) {
        return null;
    }
}

/** Démarre une MariaDB jetable si aucune ne répond déjà sur le port prévu. Renvoie le dossier de données si elle vient d'être lancée. */
function assurer_base_temporaire(array $cfg, string $travail): ?string
{
    if (pdo_temporaire($cfg)) {
        echo "   MariaDB déjà active sur le port {$cfg['tmp_port']}\n";

        return null;
    }
    $donnees = $travail.'/mariadb';
    executer([$cfg['mariadb'].'/mariadb-install-db.exe', '--datadir='.$donnees], $travail);
    pclose(popen('start /B "" "'.str_replace('/', '\\', $cfg['mariadb']).'\\mariadbd.exe" --datadir="'.str_replace('/', '\\', $donnees).'" --port='.$cfg['tmp_port'].' --bind-address=127.0.0.1 --console > NUL 2>&1', 'r'));
    for ($i = 0; $i < 60; $i++) {
        if (pdo_temporaire($cfg)) {
            echo "   MariaDB temporaire lancée sur le port {$cfg['tmp_port']}\n";

            return $donnees;
        }
        usleep(500000);
    }
    echec('la base MySQL temporaire ne démarre pas (MARIADB_BIN ?)');
}

/**
 * Compte propre à la construction. Un mot de passe vide ne peut pas servir : une variable d'environnement vide est
 * ignorée sous Windows, et Laravel retombait alors sur le mot de passe de la base LWS écrit dans le .env du paquet.
 */
function preparer_compte(array $cfg): void
{
    $pdo = pdo_temporaire($cfg);
    foreach (['127.0.0.1', 'localhost', '%'] as $hote) {
        $pdo->exec("CREATE USER IF NOT EXISTS 'kouma_build'@'{$hote}' IDENTIFIED BY 'kouma_build'");
        $pdo->exec("GRANT ALL PRIVILEGES ON *.* TO 'kouma_build'@'{$hote}'");
    }
}

function recreer_base(array $cfg, string $nom): void
{
    $pdo = pdo_temporaire($cfg);
    $pdo->exec("DROP DATABASE IF EXISTS `{$nom}`");
    $pdo->exec("CREATE DATABASE `{$nom}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

/* ============================ Liaison vers OpenAI ============================ */

function reseau_ok(): bool
{
    $ch = curl_init('https://api.openai.com/v1/models');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 8]);
    curl_exec($ch);
    $statut = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    // 401 (sans cle) prouve que le serveur répond : c'est la liaison qu'on teste, pas la clé.
    return $statut > 0;
}

/** Les étapes qui interrogent OpenAI attendent le retour de la liaison (jusqu'à 20 minutes) au lieu d'échouer. */
function attendre_reseau(string $pour): void
{
    $debut = time();
    while (! reseau_ok()) {
        if (time() - $debut > 1200) {
            echec("liaison vers api.openai.com coupée depuis plus de 20 minutes ({$pour}).");
        }
        echo "   liaison vers OpenAI indisponible, nouvel essai dans 15 s ({$pour})
";
        sleep(15);
    }
}

/* ================================ Essai HTTP ================================ */

/** @return array{0:int,1:string,2:string} statut, en-têtes, corps */
function http(string $methode, string $url, string $jar, array $champs = [], bool $suivre = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 300, CURLOPT_FOLLOWLOCATION => $suivre,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_CUSTOMREQUEST => $methode,
    ]);
    if ($methode === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($champs));
    }
    $brut = (string) curl_exec($ch);
    $statut = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $taille = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [$statut, substr($brut, 0, $taille), substr($brut, $taille)];
}

/* ================================== Montage ================================== */

$travail = str_replace('\\', '/', sys_get_temp_dir().DIRECTORY_SEPARATOR.'kouma-paquet-'.bin2hex(random_bytes(4)));
// Le dossier du site est gardé entre deux constructions (dans ../kouma-lws/.cache) pour une seule raison : son vendor.
// Le recopier (environ 20 000 fichiers) prend des minutes sur un disque lent ; tout le reste est refait à chaque fois.
$cache = $sortie.'/.cache';
$site = $cache.'/site';
$projet = $site.'/'.PROJET;
$baseLancee = null;
$serveur = null;
$code = 0;
$motDePasseAdmin = mot_de_passe_lisible();

try {
    if ($cfg['db_mot_de_passe'] === '') {
        echec('KOUMA_DB_PASSWORD absent : le mot de passe de la base LWS se passe par variable d\'environnement.');
    }
    if (! is_file($racine.'/public/build/manifest.json')) {
        echec('public/build/manifest.json absent : lancer npm run build avant.');
    }
    if (! is_file($cfg['mariadb'].'/mariadb.exe') || ! is_file($cfg['composer'])) {
        echec('MariaDB ('.$cfg['mariadb'].') ou Composer ('.$cfg['composer'].') introuvable : voir MARIADB_BIN et COMPOSER_PHAR.');
    }
    foreach (['zip', 'curl', 'pdo_mysql'] as $extension) {
        if (! extension_loaded($extension)) {
            echec("extension PHP {$extension} requise.");
        }
    }

    // Dossier temporaire : base MariaDB jetable, routeur d'essai, journaux. (Le site, lui, vit dans le cache.)
    mkdir($travail, 0777, true);

    etape('Dossier de travail (le vendor de production reste en cache d\'une construction à l\'autre)');
    @mkdir($projet, 0777, true);
    vider_dossier($site, [PROJET]);
    vider_dossier($projet, ['vendor']);

    etape('Copie du dossier du domaine (public/)');
    foreach (scandir($racine.'/public') as $nom) {
        if ($nom === '.' || $nom === '..' || in_array($nom, PUBLIC_EXCLUS, true)) {
            continue;
        }
        $de = $racine.'/public/'.$nom;
        is_dir($de) ? copier_arbre($de, $site.'/'.$nom) : copy($de, $site.'/'.$nom);
    }
    foreach (['index.php', '.htaccess'] as $nom) {
        copy($racine.'/deploiement/lws/'.$nom, $site.'/'.$nom);
    }

    etape('Copie du projet dans '.PROJET.'/');
    foreach (DOSSIERS_PROJET as $dossier) {
        copier_arbre($racine.'/'.$dossier, $projet.'/'.$dossier, $dossier);
    }
    foreach (FICHIERS_PROJET as $fichier) {
        copy($racine.'/'.$fichier, $projet.'/'.$fichier);
    }
    // Tâche planifiée pour un panneau qui n'accepte qu'un fichier PHP (équivaut à « php artisan schedule:run »).
    copy($racine.'/deploiement/lws/cron.php', $projet.'/cron.php');
    // bootstrap/cache et storage/ partent vides : ce qu'ils contiennent en local porte des chemins de cette machine.
    vider_dossier($projet.'/bootstrap/cache');
    foreach (SQUELETTE_STORAGE as $sous) {
        $dossier = $projet.'/storage/'.$sous;
        @mkdir($dossier, 0777, true);
        if (is_file($racine.'/storage/'.$sous.'/.gitignore')) {
            copy($racine.'/storage/'.$sous.'/.gitignore', $dossier.'/.gitignore');
        }
    }

    etape('Fichier .env de production');
    $recopie = ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENROUTER_API_KEY', 'DEEPINFRA_API_KEY', 'TOGETHER_API_KEY', 'VOYAGE_API_KEY', 'PLATFORM_EFFORT', 'PLATFORM_MAX_TOKENS', 'PLATFORM_MIN_SCORE', 'META_APP_SECRET', 'META_VERIFY_TOKEN', 'META_GRAPH_VERSION', 'FACEBOOK_APP_ID', 'FACEBOOK_APP_SECRET'];
    $cles = '';
    foreach ($recopie as $cle) {
        $cles .= $cle.'='.lire_env_local($racine, $cle)."\n";
    }
    $quote = fn (string $v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"';
    $cleApp = 'base64:'.base64_encode(random_bytes(32));
    $domaine = DOMAINE;
    $boite = BOITE;
    $env = <<<ENV
APP_NAME=Kouma
APP_ENV=production
APP_KEY={$cleApp}
APP_DEBUG=false
APP_URL=https://{$domaine}

APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_FAKER_LOCALE=fr_FR

APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

# Un fichier par jour, quatorze jours gardes : le quota du mutualise n'est pas extensible.
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning

# Base MySQL de LWS (le .sql livre a cote du zip s'importe dans phpMyAdmin).
DB_CONNECTION=mysql
DB_HOST={$cfg['db_hote']}
DB_PORT=3306
DB_DATABASE={$cfg['db_nom']}
DB_USERNAME={$cfg['db_utilisateur']}
DB_PASSWORD={$quote($cfg['db_mot_de_passe'])}

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
# SESSION_SECURE_COOKIE=true   a activer une fois le HTTPS verifie sur {$domaine}

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
CACHE_STORE=database

# sync : aucun processus permanent ne tourne en mutualise pour vider une file. Les reponses WhatsApp et la lecture
# des documents se font donc pendant la requete. Avec un serveur dedie : database, plus un worker.
QUEUE_CONNECTION=sync

MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mail.{$domaine}
MAIL_PORT=465
MAIL_USERNAME={$boite}
# Le mot de passe de la boite {$boite}. S'il change dans le panneau LWS, le changer ici le meme jour :
# sinon plus aucune alerte ni reinitialisation de mot de passe ne part.
MAIL_PASSWORD={$quote($cfg['boite_mot_de_passe'])}
MAIL_FROM_ADDRESS="{$boite}"
MAIL_FROM_NAME="Kouma"

VITE_APP_NAME="\${APP_NAME}"

# Compte administrateur (cree dans le .sql ; son mot de passe est dans identifiants-admin.txt).
PLATFORM_ADMIN_EMAIL={$boite}
PLATFORM_ADMIN_NAME="Administration"

# Contacts affiches sur le site. Les Parametres de la plateforme (Administration) sont prioritaires.
BRAND_EMAIL={$boite}
BRAND_WHATSAPP=

# Intelligence artificielle et canaux : copies du .env local. Une cle vide = fournisseur non utilise.
{$cles}TWILIO_WEBHOOK_BASE_URL=

ENV;
    file_put_contents($projet.'/.env', $env);
    if (lire_env_local($projet, 'OPENAI_API_KEY') === '') {
        echo "   ATTENTION : aucune clé OpenAI dans le .env local : l'assistant de la page d'accueil sera indexé sans embeddings réels.\n";
    }

    etape('vendor de production (sans les paquets de développement)');
    $empreinte = md5_file($racine.'/composer.lock');
    $fichierEmpreinte = $cache.'/vendor.md5';
    if (is_file($projet.'/vendor/autoload.php') && @file_get_contents($fichierEmpreinte) === $empreinte && getenv('KOUMA_VENDOR_FRAIS') !== '1') {
        echo "   vendor de production gardé en cache (composer.lock inchangé)
";
    } else {
        @unlink($fichierEmpreinte);
        supprimer_arbre($projet.'/vendor');
        // On part du vendor local (installé depuis le même composer.lock) : Composer n'a plus qu'à retirer les paquets de
        // développement et régénérer l'autoloader, sans rien télécharger. Une liaison lente ou coupée ne bloque donc pas
        // la construction ; un paquet manquant ou différent serait, lui, téléchargé par Composer.
        if (is_file($racine.'/vendor/autoload.php') && getenv('KOUMA_VENDOR_FRAIS') !== '1') {
            echo "   copie du vendor local, puis retrait des paquets de développement\n";
            copier_arbre($racine.'/vendor', $projet.'/vendor', 'vendor');
        }
        $outils = ['COMPOSER_NO_INTERACTION' => '1'];
        executer([PHP_BINARY, '-d', 'memory_limit=-1', $cfg['composer'], 'install', '--no-dev', '--no-scripts', '--optimize-autoloader', '--no-interaction', '--no-progress', '--prefer-dist'], $projet, $outils);
        if (is_dir($projet.'/vendor/phpunit')) {
            echec('phpunit dans le vendor de production');
        }
        // Notes pour assistants de code livrées par certains paquets : rien ne les charge, elles n'ont rien à faire en ligne.
        // On les repère d'abord, on les supprime ensuite (pas de suppression pendant le parcours).
        $notes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projet.'/vendor', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $fichier) {
            if (in_array($fichier->getFilename(), ['CLAUDE.md', 'AGENTS.md', '.claude'], true)) {
                $notes[] = [$fichier->getPathname(), $fichier->isDir()];
            }
        }
        foreach ($notes as [$chemin, $estDossier]) {
            $estDossier ? supprimer_arbre($chemin) : @unlink($chemin);
        }
        file_put_contents($fichierEmpreinte, $empreinte);
    }
    $verif = (string) @file_get_contents($projet.'/vendor/composer/platform_check.php');
    preg_match('/PHP_VERSION_ID >= (\d)(\d\d)(\d\d)/', $verif, $m);
    $phpMini = $m ? ((int) $m[1]).'.'.((int) $m[2]) : '8.2';
    echo "   PHP minimum exigé par les dépendances : {$phpMini}\n";

    etape('Base MySQL temporaire, schéma et données');
    $baseLancee = assurer_base_temporaire($cfg, $travail);
    preparer_compte($cfg);
    recreer_base($cfg, 'kouma_build');
    $envBuild = [
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $cfg['tmp_port'], 'DB_DATABASE' => 'kouma_build', 'DB_USERNAME' => 'kouma_build', 'DB_PASSWORD' => 'kouma_build',
        'PLATFORM_ADMIN_EMAIL' => BOITE, 'PLATFORM_ADMIN_PASSWORD' => $motDePasseAdmin, 'PLATFORM_ADMIN_NAME' => 'Administration',
    ];
    $artisan = fn (array $args, array $plus = [], bool $obligatoire = true) => executer(array_merge([PHP_BINARY, '-d', 'memory_limit=-1', 'artisan'], $args, ['--no-interaction']), $projet, $envBuild + $plus, $obligatoire);
    $artisan(['package:discover']);
    $artisan(['migrate', '--force']);
    $artisan(['db:seed', '--force']);
    // L'assistant de la page d'accueil demande des embeddings à OpenAI : une coupure de la liaison (DNS) peut faire
    // échouer des sources. La commande reprend celles qui manquent ; on la relance jusqu'à trois fois.
    for ($essai = 1; $essai <= 3; $essai++) {
        attendre_reseau('indexation de l\'assistant de la page d\'accueil');
        $rapport = $artisan(['platform:landing-bot'], [], false);
        echo '   '.trim(preg_replace('/\e\[[0-9;]*m/', '', $rapport)).PHP_EOL;
        if (! str_contains($rapport, 'en échec')) {
            break;
        }
        if ($essai === 3) {
            echec("l'assistant de la page d'accueil n'a pas pu être indexé (liaison vers OpenAI ?). Relancez la construction.");
        }
        sleep(10);
    }

    etape('Export .sql');
    @mkdir($sortie, 0777, true);
    $sql = $sortie.'/kouma-base.sql';
    executer([$cfg['mariadb'].'/mariadb-dump.exe', '-uroot', '-h127.0.0.1', '-P'.$cfg['tmp_port'], '--default-character-set=utf8mb4', '--single-transaction', '--skip-add-drop-table', '--skip-add-locks', '--skip-triggers', '--hex-blob', 'kouma_build'], $travail, [], true, $sql);
    // Les lignes « /*M!... */ » sont propres à MariaDB 11 : un MySQL les ignore, mais mieux vaut ne pas les livrer.
    $contenu = preg_replace('/^\/\*M!.*\*\/;?\R/m', '', (string) file_get_contents($sql));
    file_put_contents($sql, $contenu);
    foreach (['CREATE TABLE `users`', 'CREATE TABLE `bots`', 'INSERT INTO `plans`', 'INSERT INTO `platform_settings`', 'INSERT INTO `chunks`'] as $attendu) {
        if (! str_contains($contenu, $attendu)) {
            echec("le .sql ne contient pas « {$attendu} »");
        }
    }
    echo '   '.round(strlen($contenu) / 1024).' Ko, '.substr_count($contenu, 'CREATE TABLE')." tables\n";

    // L'essai local importe la version sans « USE » (la base d'essai porte un autre nom) ; le fichier livré, lui,
    // commence par « USE » : importé depuis l'onglet « Importer » du serveur phpMyAdmin (sans avoir cliqué sur la base
    // avant), il échouerait sinon avec « #1046 Aucune base n'a été sélectionnée ».
    $sqlEssai = $travail.'/essai.sql';
    file_put_contents($sqlEssai, $contenu);
    file_put_contents($sql, "-- Kouma : base de données à importer dans phpMyAdmin.\nUSE `{$cfg['db_nom']}`;\n\n".$contenu);

    etape('Essai du montage : import du .sql puis site servi par PHP');
    recreer_base($cfg, 'kouma_essai');
    executer([$cfg['mariadb'].'/mariadb.exe', '-uroot', '-h127.0.0.1', '-P'.$cfg['tmp_port'], 'kouma_essai', '-e', 'source '.$sqlEssai], $travail);
    $envEssai = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $cfg['tmp_port'], 'DB_DATABASE' => 'kouma_essai', 'DB_USERNAME' => 'kouma_build', 'DB_PASSWORD' => 'kouma_build'];

    $routeur = $travail.'/routeur.php';
    file_put_contents($routeur, <<<'ROUTEUR'
<?php
// Remplace le .htaccess pour le seul essai local : un fichier existant est servi tel quel, le dossier du projet
// est interdit, tout le reste passe par index.php. Jamais dans le paquet.
$chemin = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (str_starts_with($chemin, '/kouma/')) { http_response_code(403); return true; }
if ($chemin !== '/' && is_file($_SERVER['DOCUMENT_ROOT'].$chemin)) { return false; }
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'].'/index.php';
require $_SERVER['DOCUMENT_ROOT'].'/index.php';
ROUTEUR);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $serveur = proc_open([PHP_BINARY, '-d', 'memory_limit=-1', '-d', 'max_execution_time=300', '-S', "127.0.0.1:{$port}", '-t', $site, $routeur], [0 => ['pipe', 'r'], 1 => ['file', $travail.'/serveur.log', 'w'], 2 => ['file', $travail.'/serveur.log', 'a']], $tubes, $site, array_merge(getenv(), $envEssai));
    for ($i = 0; $i < 60; $i++) {
        if (@fsockopen('127.0.0.1', $port, $e, $s, 0.3)) {
            break;
        }
        usleep(250000);
    }

    $base = "http://127.0.0.1:{$port}";
    $jar = $travail.'/cookies.txt';
    $verifications = [
        ['GET', '/', 200, 'chatbot WhatsApp'], ['GET', '/ressources', 200, 'Ressources'], ['GET', '/chatbot-whatsapp', 200, 'chatbot WhatsApp'],
        ['GET', '/guides/combien-coute-un-chatbot-whatsapp', 200, 'Bon plan'], ['GET', '/login', 200, 'Mot de passe oublié'], ['GET', '/register', 200, 'Créez votre assistant'],
        ['GET', '/forgot-password', 200, 'Recevoir le lien'], ['GET', '/developpeurs', 200, 'API'], ['GET', '/conditions', 200, null], ['GET', '/confidentialite', 200, null],
        ['GET', '/sitemap.xml', 200, '<urlset'], ['GET', '/robots.txt', 200, 'Sitemap: https://'.DOMAINE.'/sitemap.xml'], ['GET', '/llms.txt', 200, '# Kouma'],
        ['GET', '/manifest.webmanifest', 200, 'standalone'], ['GET', '/build/manifest.json', 200, null], ['GET', '/widget/widget.js', 200, null], ['GET', '/sw.js', 200, null],
        ['GET', '/favicon.ico', 200, null], ['GET', '/og-image.png', 200, null],
        ['GET', '/kouma/.env', 403, null], ['GET', '/kouma/storage/logs/laravel.log', 403, null], ['GET', '/dashboard', 302, null],
    ];
    $problemes = [];
    foreach ($verifications as [$methode, $chemin, $attendu, $texte]) {
        [$statut, , $corps] = http($methode, $base.$chemin, $jar);
        $ok = $statut === $attendu && ($texte === null || str_contains($corps, $texte));
        printf("   %-48s %s%s\n", $chemin, $statut, $ok ? '' : '   <-- ATTENDU '.$attendu.($texte ? " + « {$texte} »" : ''));
        if (! $ok) {
            $problemes[$chemin] = $statut;
        }
    }
    if (str_contains((string) (http('GET', $base.'/robots.txt', $jar)[2]), "Disallow:\n")) {
        $problemes['robots.txt statique'] = 'robots.txt trop permissif';
    }

    // Connexion de l'administrateur, puis pages protégées et profil.
    [, , $page] = http('GET', $base.'/login', $jar);
    preg_match('/name="_token" value="([^"]+)"/', $page, $t);
    [$statut, $entetes] = http('POST', $base.'/login', $jar, ['_token' => $t[1] ?? '', 'email' => BOITE, 'password' => $motDePasseAdmin]);
    printf("   %-48s %s\n", 'connexion de '.BOITE, $statut);
    if ($statut !== 302 || ! preg_match('/Location: .*\/(admin|dashboard)/i', $entetes)) {
        $problemes['connexion'] = $statut;
    }
    foreach (['/admin' => '', '/profile' => 'Mes informations', '/admin/plans' => 'Bon plan', '/admin/settings' => 'Paramètres'] as $chemin => $texte) {
        [$statut, , $corps] = http('GET', $base.$chemin, $jar);
        $ok = $statut === 200 && str_contains($corps, $texte);
        printf("   %-48s %s%s\n", $chemin.' (connecté)', $statut, $ok ? '' : '   <-- ATTENDU 200 + « '.$texte.' »');
        if (! $ok) {
            $problemes[$chemin] = $statut;
        }
    }

    // Une vraie réponse de l'assistant de la page d'accueil, avec les clés de production et la base importée.
    // Une coupure passagère de la liaison (DNS) fait retomber la recherche sur les mots exacts : on réessaie.
    $idAssistant = trim(executer([PHP_BINARY, 'artisan', 'tinker', '--execute', "echo App\Models\Bot::withoutGlobalScopes()->where('name','Assistant Kouma')->value('id');"], $projet, $envEssai));
    $reponseOk = false;
    for ($essai = 1; $essai <= 4 && ! $reponseOk; $essai++) {
        attendre_reseau('réponse réelle');
        $reponse = executer([PHP_BINARY, '-d', 'memory_limit=-1', 'artisan', 'platform:ask', $idAssistant, 'Combien ça coûte ?'], $projet, $envEssai, false);
        // Le prix s'écrit avec une espace fine insécable dans les sources : le modèle peut la garder ou la normaliser.
        $reponseOk = str_contains($reponse, 'grounded=true') && preg_match('/25[\s\x{202F}\x{00A0}]*000/u', $reponse) === 1;
        if (! $reponseOk && $essai < 4) {
            echo "   réponse réelle : essai {$essai} sans succès (liaison ?), nouvel essai dans 8 s\n";
            sleep(8);
        }
    }
    printf("   %-48s %s\n", 'réponse réelle « Combien ça coûte ? »', $reponseOk ? 'OK (sourcée, prix à jour)' : 'ÉCHEC');
    if (! $reponseOk) {
        $problemes['réponse'] = mb_substr(trim($reponse), 0, 300);
    }

    if ($problemes) {
        $journal = is_dir($projet.'/storage/logs') ? implode("\n", array_map(fn ($f) => mb_substr((string) file_get_contents($f), -3000), glob($projet.'/storage/logs/*.log') ?: [])) : '';
        echec("l'essai du montage a échoué : ".json_encode($problemes, JSON_UNESCAPED_UNICODE)."\n".$journal);
    }

    etape('Ménage après l\'essai');
    proc_terminate($serveur);
    $serveur = null;
    foreach (['logs', 'framework/views', 'framework/sessions', 'framework/cache/data'] as $sous) {
        vider_dossier($projet.'/storage/'.$sous);
    }
    foreach (scandir($projet.'/bootstrap/cache') as $nom) {
        if (! in_array($nom, ['.', '..', '.gitignore', 'packages.php', 'services.php'], true)) {
            @unlink($projet.'/bootstrap/cache/'.$nom);
        }
    }
    foreach (['packages.php', 'services.php'] as $nom) {
        if (! is_file($projet.'/bootstrap/cache/'.$nom)) {
            echec('bootstrap/cache/'.$nom.' manquant');
        }
    }

    $lisezMoi = <<<TXT
KOUMA, installation chez LWS (hébergement mutualisé)
====================================================

Contenu du paquet
  kouma.site-lws.zip   le site : à décompresser dans le dossier du domaine kouma.site
  kouma-base.sql       la base de données : à importer UNE fois dans phpMyAdmin
  identifiants-admin.txt   le compte administrateur et son mot de passe

Le zip contient des secrets (kouma/.env : mot de passe de la base, clés d'IA, mot de passe de la boîte e-mail).
Ne l'envoyez à personne et ne le mettez pas dans un dépôt.

1. Panneau LWS, PHP : choisir PHP {$phpMini} ou plus (8.3 conseillé) pour {$domaine}.
2. Gestionnaire de fichiers LWS : ouvrir le dossier du domaine {$domaine} (celui qui contient la page d'attente de
   l'hébergeur), envoyer kouma.site-lws.zip, puis « Extraire » à cet endroit. Après extraction, ce dossier contient
   .htaccess, index.php, build/, widget/ et le dossier kouma/. La page d'attente de l'hébergeur (index.html) peut être
   supprimée ; sinon .htaccess la contourne.
   SUPPRIMER ENSUITE kouma.site-lws.zip du serveur : il contient les mots de passe (kouma/.env) et, laissé dans le
   dossier du domaine, il serait téléchargeable par n'importe qui. Pareil pour kouma-base.sql après l'import.
3. phpMyAdmin (lien reçu par e-mail de LWS) : cliquer la base {$cfg['db_nom']}, onglet « Importer », choisir
   kouma-base.sql, valider. Elle crée les tables, les offres, le compte administrateur et l'assistant de la page d'accueil.
   (Le fichier commence par « USE {$cfg['db_nom']} » : il marche même importé depuis l'onglet du serveur, sans base
   cliquée. Si phpMyAdmin répond « #1046 Aucune base n'a été sélectionnée » avec une ancienne copie du fichier,
   cliquer d'abord la base dans la colonne de gauche, puis « Importer ».)
4. Vérifier dans un navigateur que ces deux adresses répondent 403 ou 404, jamais un contenu :
     https://{$domaine}/kouma/.env
     https://{$domaine}/kouma/storage/logs/
5. Certificat HTTPS gratuit (Let's Encrypt / AutoSSL) dans le panneau LWS. Une fois https://{$domaine} vérifié seulement :
   - décommenter la ligne « Header always set Strict-Transport-Security » dans .htaccess ;
   - dans kouma/.env, décommenter SESSION_SECURE_COOKIE=true.
6. Tâche planifiée (panneau LWS, « Tâches CRON »), toutes les minutes, pour les rappels de demandes, les relectures de
   sites et les abonnements :
     /usr/local/bin/php  CHEMIN_DU_DOSSIER/kouma/artisan schedule:run
   (le chemin complet du dossier est indiqué dans le gestionnaire de fichiers LWS ; la commande php peut s'appeler
   différemment, voir l'aide LWS « Tâches CRON »). Si le panneau n'accepte qu'un fichier à exécuter, sans arguments,
   choisir kouma/cron.php : il fait la même chose. Toutes les minutes, ou toutes les 5 minutes si c'est le minimum.
7. Connexion : https://{$domaine}/login avec {$boite} et le mot de passe de identifiants-admin.txt. Changer ce mot de
   passe dans « Mon profil ».
8. Essai de l'e-mail : « Mot de passe oublié » sur la page de connexion avec {$boite}. Le message arrive dans le
   webmail https://mail.{$domaine}. Sinon, vérifier MAIL_PASSWORD dans kouma/.env.
9. Dans Administration, Paramètres : renseigner le numéro WhatsApp commercial (le bouton WhatsApp du site n'apparaît
   qu'avec un numéro), la raison sociale et l'adresse de l'éditeur (pages juridiques). Puis Search Console : voir docs/SEO.md.

Ce que fait le serveur tout seul
  - La base est MySQL (kouma/.env, DB_*). Les sessions et le cache y sont aussi.
  - Les erreurs s'écrivent dans kouma/storage/logs/ (un fichier par jour, 14 jours gardés).
  - Les documents envoyés par les clients sont dans kouma/storage/app/private/, jamais servis au public.

Mise à jour du site plus tard : remplacer les dossiers kouma/app, kouma/resources, kouma/routes, kouma/config,
kouma/database, kouma/vendor (si les dépendances changent) et build/, sans toucher à kouma/.env ni kouma/storage.
Les nouvelles tables se créent avec « php artisan migrate --force » (SSH) ou avec un nouveau .sql de migration.
TXT;
    file_put_contents($projet.'/LISEZ-MOI.txt', str_replace("\r\n", "\n", $lisezMoi)."\n");
    copy($projet.'/LISEZ-MOI.txt', $sortie.'/LISEZ-MOI.txt');

    etape('Zip');
    $zip = $sortie.'/kouma.site-lws.zip';
    @unlink($zip);
    $archive = new ZipArchive;
    if ($archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echec('zip impossible à créer');
    }
    $noms = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterateur as $element) {
        $arc = str_replace('\\', '/', substr($element->getPathname(), strlen($site) + 1));
        if ($element->isDir()) {
            $archive->addEmptyDir($arc);
            $archive->setExternalAttributesName($arc.'/', ZipArchive::OPSYS_UNIX, 040755 << 16);
        } else {
            $archive->addFile($element->getPathname(), $arc);
            $archive->setExternalAttributesName($arc, ZipArchive::OPSYS_UNIX, 0100644 << 16);
            $noms[] = $arc;
        }
    }
    $archive->close();

    $requis = ['.htaccess', 'index.php', 'build/manifest.json', 'widget/widget.js', 'favicon.ico', PROJET.'/.env', PROJET.'/artisan', PROJET.'/vendor/autoload.php', PROJET.'/bootstrap/app.php', PROJET.'/bootstrap/cache/packages.php', PROJET.'/storage/logs/.gitignore', PROJET.'/LISEZ-MOI.txt'];
    $manquants = array_values(array_diff($requis, $noms));
    $interdits = array_values(array_filter($noms, fn ($n) => in_array(basename($n), ['CLAUDE.md', 'AGENTS.md'], true) || str_contains($n, '.claude/') || str_contains($n, '/node_modules/')
        || str_starts_with($n, PROJET.'/tests/') || str_starts_with($n, PROJET.'/public/') || str_starts_with($n, PROJET.'/vendor/phpunit/') || str_ends_with($n, '.sqlite') || $n === 'robots.txt'));
    if ($manquants || $interdits) {
        @unlink($zip);
        echec("paquet incomplet ou pollué.\nmanquants : ".json_encode($manquants)."\ninterdits : ".json_encode(array_slice($interdits, 0, 10)));
    }

    file_put_contents($sortie.'/identifiants-admin.txt', implode("\n", [
        'Kouma : compte administrateur (fichier à garder hors de tout dépôt)',
        '',
        'Adresse de connexion : https://'.DOMAINE.'/login',
        'Identifiant          : '.BOITE,
        'Mot de passe         : '.$motDePasseAdmin,
        '',
        'À changer dans « Mon profil » dès la première connexion.',
        '',
    ]));

    $taille = round(filesize($zip) / (1024 * 1024), 1);
    echo "\n".str_repeat('=', 64)."\n";
    echo "PAQUET   : {$zip}\n";
    echo '           '.$taille.' Mo, '.count($noms)." fichiers\n";
    echo "BASE     : {$sql}\n";
    echo "COMPTE   : {$sortie}/identifiants-admin.txt\n";
    echo str_repeat('=', 64)."\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nECHEC : ".$e->getMessage()."\n");
    $code = 1;
} finally {
    if (is_resource($serveur)) {
        proc_terminate($serveur);
    }
    if ($baseLancee !== null) {
        @executer([$cfg['mariadb'].'/mariadb-admin.exe', '-uroot', '-h127.0.0.1', '-P'.$cfg['tmp_port'], 'shutdown'], $travail, [], false);
        sleep(2);
    }
    supprimer_arbre($travail);
}

exit($code);
