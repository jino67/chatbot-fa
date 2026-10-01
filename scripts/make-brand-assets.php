<?php

/*
 * Génère les images de marque à partir du dessin du logo (le « o » qui parle : deux demi-cercles) :
 *   public/apple-touch-icon.png (180), public/icon-192.png, public/icon-512.png, public/icon-maskable-512.png,
 *   public/og-image.png (1200 x 630, aperçu des liens partagés et des résultats de recherche), public/favicon.ico.
 * Usage : php scripts/make-brand-assets.php   (extension GD requise)
 */

const COBALT = [35, 64, 217];
const SAFRAN = [255, 180, 0];
const MARINE = [11, 19, 64];
const BLANC = [255, 255, 255];

$out = dirname(__DIR__).'/public/';

/** Image sur-échantillonnée (x4) puis réduite : les arcs sortent lisses. */
function canvas(int $w, int $h, array $bg, int $scale = 4)
{
    $im = imagecreatetruecolor($w * $scale, $h * $scale);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$bg));

    return $im;
}

function color($im, array $rgb, int $alpha = 0)
{
    return imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], $alpha);
}

/** Dessine la marque : boîte englobante de 38 unités de large sur 40 de haut, centrée en ($cx, $cy). */
function mark($im, float $cx, float $cy, float $size, array $top, array $bottom): void
{
    $k = $size / 38;
    $r = 17 * $k * 2;
    // Demi-cercle du haut : centre (22, 21) ; du bas : centre (26, 27), dans un repère centré en (24, 24).
    imagefilledarc($im, (int) ($cx + (22 - 24) * $k), (int) ($cy + (21 - 24) * $k), (int) $r, (int) $r, 180, 360, color($im, $top), IMG_ARC_PIE);
    imagefilledarc($im, (int) ($cx + (26 - 24) * $k), (int) ($cy + (27 - 24) * $k), (int) $r, (int) $r, 0, 180, color($im, $bottom), IMG_ARC_PIE);
}

function save($im, int $w, int $h, int $scale, string $path): void
{
    $final = imagecreatetruecolor($w, $h);
    imagecopyresampled($final, $im, 0, 0, 0, 0, $w, $h, $w * $scale, $h * $scale);
    imagepng($final, $path, 9);
    printf("%s (%d x %d, %d octets)\n", basename($path), $w, $h, filesize($path));
}

// ---- Icônes d'application : fond cobalt plein (iOS et Android arrondissent eux-mêmes), marque blanche et safran ----
foreach ([['apple-touch-icon.png', 180, 0.60], ['icon-192.png', 192, 0.60], ['icon-512.png', 512, 0.60], ['icon-maskable-512.png', 512, 0.42]] as [$file, $size, $fraction]) {
    $scale = 4;
    $im = canvas($size, $size, COBALT, $scale);
    mark($im, $size * $scale / 2, $size * $scale / 2, $size * $scale * $fraction, BLANC, SAFRAN);
    save($im, $size, $size, $scale, $out.$file);
}

// ---- Aperçu des liens partagés ----
$w = 1200;
$h = 630;
$scale = 2;
$im = canvas($w, $h, COBALT, $scale);

// Motif « demi-cercles » de la marque, très discret
$soft = color($im, [95, 125, 240], 105);
for ($y = 0; $y < $h; $y += 64) {
    for ($x = 0; $x < $w; $x += 64) {
        imagefilledarc($im, ($x + 19) * $scale, ($y + 28) * $scale, 26 * $scale, 26 * $scale, 180, 360, $soft, IMG_ARC_PIE);
    }
}

// Grande marque à droite, puis textes à gauche
mark($im, 930 * $scale, 315 * $scale, 380 * $scale, BLANC, SAFRAN);

$font = null;
foreach (['C:/Windows/Fonts/segoeuib.ttf', 'C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'] as $candidate) {
    if (is_file($candidate)) {
        $font = $candidate;
        break;
    }
}

if ($font) {
    $white = color($im, BLANC);
    $saffron = color($im, SAFRAN);
    imagettftext($im, 92 * $scale * 0.75, 0, 72 * $scale, 250 * $scale, $white, $font, 'Kouma');
    imagettftext($im, 40 * $scale * 0.75, 0, 76 * $scale, 340 * $scale, $white, $font, 'Répondez à vos clients');
    imagettftext($im, 40 * $scale * 0.75, 0, 76 * $scale, 396 * $scale, $white, $font, 'à toute heure');
    imagettftext($im, 26 * $scale * 0.75, 0, 76 * $scale, 480 * $scale, $saffron, $font, 'Chatbot WhatsApp et site web pour PME');
}

save($im, $w, $h, $scale, $out.'og-image.png');

// favicon.ico : un PNG 48 x 48 dans un conteneur ICO. Les robots et les vieux navigateurs demandent /favicon.ico
// sans lire les balises de la page : un fichier vide (ce qu'il y avait) donne une icône cassée dans les onglets.
$source = imagecreatefrompng($out.'icon-192.png');
$small = imagecreatetruecolor(48, 48);
imagealphablending($small, false);
imagesavealpha($small, true);
imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
imagecopyresampled($small, $source, 0, 0, 0, 0, 48, 48, imagesx($source), imagesy($source));
ob_start();
imagepng($small, null, 9);
$png = (string) ob_get_clean();
file_put_contents($out.'favicon.ico', pack('vvv', 0, 1, 1).pack('CCCCvvVV', 48, 48, 0, 0, 1, 32, strlen($png), 22).$png);
