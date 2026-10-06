<?php

namespace App\Support;

/**
 * Photos : relire une image quel que soit son format (jpeg, png, webp, gif) et la rendre en JPEG de taille raisonnable.
 * WhatsApp n'accepte en photo que le jpeg et le png ; une photo trop lourde est lente à charger sur un téléphone.
 * Le réencodage efface aussi les métadonnées (position GPS, appareil) des photos des clients.
 */
final class ImageTools
{
    /** Au-delà, la mémoire de GD serait trop sollicitée. */
    private const MAX_PIXELS = 40_000_000;

    /** @return array{bytes:string, width:int, height:int}|null null si ce n'est pas une image lisible */
    public static function jpeg(string $binary, int $maxSide = 1000, int $quality = 82): ?array
    {
        if ($binary === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $info = @getimagesizefromstring($binary);
        if (! $info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if (! $source) {
            return null;
        }

        [$width, $height] = $info;
        $scale = min(1, $maxSide / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // La transparence est aplatie sur fond blanc : un JPEG n'en a pas.
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $bytes = (string) ob_get_clean();
        unset($source, $canvas);

        return $bytes !== '' ? ['bytes' => $bytes, 'width' => $newWidth, 'height' => $newHeight] : null;
    }
}
