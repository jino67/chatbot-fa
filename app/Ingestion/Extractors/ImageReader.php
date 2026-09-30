<?php

namespace App\Ingestion\Extractors;

use App\Ai\Llm\LlmClient;
use App\Ai\LlmException;
use App\Ingestion\ExtractedDocument;
use App\Ingestion\IngestionException;
use App\Support\Text;

/**
 * Photos : affiches de prix, menus, flyers, vitrines. Claude lit l'image (OCR + description)
 * et le texte obtenu est indexe comme n'importe quel autre document.
 */
class ImageReader
{
    private const MAX_SIDE = 1568;          // au-dela, l'API redimensionne de toute facon

    private const MAX_PIXELS = 40_000_000;  // garde-fou memoire GD

    private const INSTRUCTION = "Tu prépares le contenu d'une base de connaissances pour l'assistant d'une entreprise. "
        .'Transcris fidèlement tout le texte lisible de cette image (menus, prix, horaires, adresses, numéros de téléphone, conditions) '
        .'en conservant la structure : titres, listes, et les tarifs sous la forme « produit : prix ». '
        ."Ajoute ensuite une courte description de ce que montre l'image si elle présente des produits, des lieux ou des services utiles à des clients. "
        ."N'invente rien : écris [illisible] pour un passage illisible. Réponds en Markdown, sans commentaire autour.";

    public function __construct(private readonly LlmClient $llm) {}

    public function read(string $path, string $title): ExtractedDocument
    {
        [$binary, $mime] = $this->prepare($path);

        try {
            $text = Text::clean($this->llm->transcribe($binary, $mime, self::INSTRUCTION));
        } catch (LlmException $e) {
            throw new IngestionException($e->getMessage());
        }

        if (mb_strlen($text) < 10) {
            throw new IngestionException("Aucun contenu exploitable n'a été trouvé dans cette image.");
        }

        return new ExtractedDocument($title, $text);
    }

    /** @return array{0:string, 1:string} binaire et type MIME prets pour l'API */
    private function prepare(string $path): array
    {
        $info = @getimagesize($path);
        if (! $info) {
            throw new IngestionException("Ce fichier n'est pas une image valide.");
        }

        [$width, $height] = $info;
        if ($width * $height > self::MAX_PIXELS) {
            throw new IngestionException("Cette image est trop grande ({$width}x{$height}). Réduisez-la avant de l'envoyer.");
        }

        $original = file_get_contents($path);
        if (! function_exists('imagecreatefromstring')) {
            return [$original, $info['mime']];
        }

        $source = @imagecreatefromstring($original);
        if (! $source) {
            return [$original, $info['mime']];
        }

        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // Aplatit la transparence sur fond blanc puis reencode en JPEG (plus leger, accepte partout).
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg = ob_get_clean();

        return [$jpeg, 'image/jpeg'];
    }
}
