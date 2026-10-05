<?php

namespace App\Chat;

use App\Ai\Llm\LlmClient;
use App\Ai\LlmException;
use App\Models\Bot;
use App\Services\UsageMeter;
use App\Support\Text;

/**
 * Fait lire une photo de client par le modèle de vision, avec le brief du métier de l'entreprise, et en tire un résultat
 * court et sûr : catégorie, résumé, détails, et si la photo est sensible (pièce d'identité, carte bancaire). Le texte rendu
 * par le modèle vient d'une image que n'importe qui peut avoir préparée : il est borné, nettoyé, et traité comme une donnée.
 */
class VisionAnalyzer
{
    public function __construct(private readonly LlmClient $llm, private readonly UsageMeter $meter) {}

    /**
     * @return array{category:string, summary:string, details:string, sensitive:bool}
     *
     * @throws LlmException si aucun modèle ne sait lire les photos
     */
    public function analyze(Bot $bot, string $jpeg, ?string $caption = null): array
    {
        $raw = $this->llm->transcribe($jpeg, 'image/jpeg', VisionBrief::instruction($bot, $caption));

        // Le coût d'une lecture de photo compte dans la consommation de l'entreprise (page « Consommation »).
        $this->meter->ai($bot->workspace_id, $bot->id, 'vision', null, 1100, 220);

        return self::parse($raw);
    }

    /** @return array{category:string, summary:string, details:string, sensitive:bool} */
    public static function parse(string $raw): array
    {
        $fields = ['categorie' => '', 'resume' => '', 'details' => '', 'sensible' => ''];
        $current = null;

        foreach (preg_split('/\R/u', $raw) as $line) {
            if (preg_match('/^\s*[*#>\-\s]*(CATEGORIE|CATÉGORIE|RESUME|RÉSUMÉ|DETAILS|DÉTAILS|SENSIBLE)\s*\**\s*:?\s*\**\s*(.*)$/iu', $line, $m)) {
                $current = strtr(mb_strtolower($m[1]), ['é' => 'e']);
                $fields[$current] = trim($m[2]);
            } elseif ($current !== null && trim($line) !== '') {
                $fields[$current] .= ' '.trim($line);
            }
        }

        $category = Text::fold(trim(preg_replace('/[^\p{L}]+/u', ' ', $fields['categorie'])));
        $category = collect(VisionBrief::CATEGORIES)->first(fn ($c) => str_contains($category, $c)) ?? 'autre';

        $sensitive = (bool) preg_match('/^\s*(oui|yes|true)\b/iu', $fields['sensible']) || $category === 'document' && str_contains(Text::fold($fields['resume']), 'confidentiel');

        $clean = fn (string $text, int $max) => Text::limit(trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\[\[[^\]]*\]\]|<[^>]{0,40}>/u', '', $text))), $max);

        return [
            'category' => $category,
            'summary' => $sensitive ? 'Document confidentiel (non retenu).' : ($clean($fields['resume'], 500) ?: ($category === 'illisible' ? 'Photo illisible.' : 'Photo reçue.')),
            'details' => $sensitive || in_array(Text::fold($fields['details']), ['aucun', 'aucune', 'rien', ''], true) ? '' : $clean($fields['details'], 600),
            'sensitive' => $sensitive,
        ];
    }
}
