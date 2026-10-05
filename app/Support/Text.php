<?php

namespace App\Support;

/**
 * Utilitaires texte partages par l'ingestion, la recherche lexicale et les embeddings locaux.
 */
final class Text
{
    private const STOPWORDS = [
        // francais
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'l', 'et', 'ou', 'a', 'au', 'aux', 'en', 'dans', 'sur', 'sous',
        'pour', 'par', 'avec', 'sans', 'ce', 'cet', 'cette', 'ces', 'se', 'sa', 'son', 'ses', 'ma', 'mon', 'mes', 'ta', 'ton',
        'tes', 'notre', 'nos', 'votre', 'vos', 'leur', 'leurs', 'qui', 'que', 'quoi', 'dont', 'est', 'sont', 'etre', 'avoir',
        'ai', 'as', 'avez', 'ont', 'il', 'elle', 'ils', 'elles', 'on', 'nous', 'vous', 'je', 'tu', 'me', 'te', 'ne', 'pas',
        'plus', 'tres', 'y', 'ca', 'cela', 'qu', 'c', 's', 'n', 'j', 'm', 't', 'si', 'mais', 'donc', 'car', 'comme', 'quel',
        'quelle', 'quels', 'quelles', 'quand', 'comment', 'combien', 'peut', 'peux', 'puis', 'faire', 'fait',
        // anglais
        'the', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with', 'is', 'are', 'was', 'were', 'be', 'it', 'this', 'that',
        'these', 'those', 'an', 'as', 'at', 'by', 'from', 'do', 'does', 'how', 'what', 'when', 'where', 'which', 'who', 'can',
        'you', 'your', 'we', 'our', 'i', 'my',
    ];

    private static ?\Transliterator $stripper = null;

    /** Minuscules et sans accents (les alphabets non latins sont conserves). */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text);

        if (class_exists(\Transliterator::class)) {
            self::$stripper ??= \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            $folded = self::$stripper?->transliterate($text);
            if (is_string($folded)) {
                return $folded;
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text, bool $dropStopwords = true): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', self::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];

        foreach ($parts as $token) {
            if ($dropStopwords && in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            if (mb_strlen($token) < 2 && ! ctype_digit($token)) {
                continue;
            }
            $out[] = self::stem($token);
        }

        return $out;
    }

    /** Racinisation minimale : pluriels francais et anglais. */
    private static function stem(string $token): string
    {
        if (mb_strlen($token) > 4 && (str_ends_with($token, 's') || str_ends_with($token, 'x'))) {
            return mb_substr($token, 0, -1);
        }

        return $token;
    }

    public static function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    /**
     * Salutation, remerciement ou prise de nouvelles (« Bonsoir, comment tu vas ? ») : pas la peine d'aller chercher
     * dans la base de connaissances, et surtout pas de répondre « je n'ai pas cette information » à un bonsoir.
     */
    public static function isSmallTalk(string $text): bool
    {
        $folded = str_replace(['’', '-'], ["'", ' '], trim(self::fold($text)));

        if (mb_strlen($folded) > 40) {
            return false;
        }

        $greeting = 'bonjour|bonsoir|salut|coucou|hello|hi|hey|salam|assalam\S*|marhaba|merci|thanks?|thank you|ok|okay|d\'accord|super|parfait|au revoir|bye|a bientot|bonne journee|bonne soiree';
        $news = '(?:comment (?:tu vas|vas tu|ca va|allez vous|vous allez|vous portez vous|tu te portes)|ca va|tu vas bien|vous allez bien|how are you)(?: bien| aujourd\'hui)?';
        $filler = 'a tous|tout le monde|monsieur|madame|beaucoup|bien|vous';

        return (bool) preg_match(
            "/^(?=[\\s!.,?]*[a-z])(?:(?:{$greeting})(?:[\\s!.,?]+(?:{$filler}))*)?[\\s!.,?]*(?:{$news})?[\\s!.,?]*$/u",
            $folded
        );
    }

    /**
     * Fil d'Ariane "Titre › Section › Sous-section" : sans doublon quand la section reprend deja le titre,
     * et sans caractere ">" (qui casserait les balises du prompt).
     */
    public static function breadcrumb(?string $title, ?string $heading): string
    {
        $title = trim((string) $title);
        $heading = trim((string) $heading);
        $parts = [];

        if ($title !== '' && ($heading === '' || ! str_starts_with(self::fold($heading), self::fold($title)))) {
            $parts[] = $title;
        }
        if ($heading !== '') {
            $parts[] = $heading;
        }

        return str_replace(['>', ' > '], ['›', ' › '], implode(' › ', $parts));
    }

    /**
     * Le client demande explicitement une personne. Detection deterministe (sans LLM ni recherche) :
     * un client qui veut un humain ne doit jamais dependre de la qualite de la base de connaissances.
     */
    public static function wantsHuman(string $text): bool
    {
        $folded = self::fold($text);

        foreach ([
            '/\b(parler|discuter|echanger|joindre|contacter|avoir|voir)\b.{0,25}\b(quelqu\W?un|humain|personne|conseiller|conseillere|responsable|vendeur|vendeuse|gerant|gerante|equipe|service client)\b/u',
            '/\b(un|une|votre)\s+(humain|vrai(e)?\s+personne|conseiller|conseillere|responsable)\b/u',
            '/\b(rappelez|appelez|contactez)[\s-]+moi\b/u',
            '/\b(speak|talk|chat)\b.{0,20}\b(human|person|agent|someone|representative|manager)\b/u',
            '/\b(real|human)\s+(person|agent|being)\b/u',
            '/\bcustomer (service|support)\b/u',
            '/(موظف|شخص حقيقي|بشري|خدمة العملاء|اريد التحدث)/u',
        ] as $pattern) {
            if (preg_match($pattern, $folded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le client demande à VOIR un produit (photo, image, « montre-moi »). Détection sans modèle : une photo promise doit partir
     * même si l'assistant a oublié de la joindre. Un client qui annonce SA photo (« voici la photo de mon visage ») ne la demande pas.
     */
    public static function wantsPhoto(string $text): bool
    {
        // Apostrophes et traits d'union deviennent des espaces : « montre-moi », « j'aimerais voir », « y a-t-il ».
        $folded = str_replace(['’', "'", '-'], ' ', self::fold($text));

        if (preg_match('/\b(voici|ci joint|ci-joint|je vous (envoie|ai envoye)|j ai envoye|je t envoie|here is|attached)\b/u', $folded)) {
            return false;
        }

        foreach ([
            '/\b(montr\w+|envo[iy]\w*|donn\w+|pass\w+|voir|voyez|voyons|vois|show|send)\b.{0,40}\b(photos?|images?|pics?|pictures?)\b/u',
            '/\b(photos?|images?)\b.{0,25}\b(du|de la|des|de l|d)\b.{0,50}\?/u',
            '/\ba quoi (ca|il|elle|ils|elles)\b.{0,12}\bressembl/u',
            '/\b(montre|montrez)[ -]?(moi|nous)\b/u',
            '/\b(je veux|j aimerais|je voudrais|puis je|peux je|on peut|je peux) voir\b/u',
            '/\b(vous avez|t as|tu as|y a t il|avez vous|as tu) (une |des |la |les )?(photos?|images?)\b/u',
        ] as $pattern) {
            if (preg_match($pattern, $folded)) {
                return true;
            }
        }

        return false;
    }

    /** Le client demande une réponse en audio (« explique-moi en audio », « envoie-moi un vocal »). */
    public static function wantsAudio(string $text): bool
    {
        $folded = str_replace(['’', "'", '-'], ' ', self::fold($text));

        return (bool) preg_match('/\b(en audio|en vocal|par vocal|par audio|message vocal|un vocal|a voix haute|voice (message|note)|in audio)\b/u', $folded)
            || (bool) preg_match('/\b(audio|vocal)\b.{0,20}\b(svp|stp|please|s il (te|vous) plait)\b/u', $folded);
    }

    /**
     * Message de reclamation ou d'urgence : meme sans extrait pertinent, on laisse le LLM
     * repondre avec empathie et decider d'un transfert (marqueur [[HANDOFF]]).
     */
    public static function isSensitive(string $text): bool
    {
        return (bool) preg_match(
            '/\b(plainte|arnaque|arnaquer|escroc\w*|inadmissible|scandale|honte|rembours\w*|urgent\w*|catastroph\w*|casse\w*|defectueux|endommage\w*|jamais recu|pas recu|inacceptable|mecontent\w*|decu|furieux|complaint|refund|scam|terrible|unacceptable|angry|urgent)\b/u',
            self::fold($text)
        );
    }

    /** Coupe proprement a une limite de caracteres, sans casser un mot. */
    public static function limit(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space ? mb_substr($cut, 0, $space) : $cut).'…';
    }

    /** Nettoie un texte extrait : fins de ligne, espaces multiples, lignes vides en cascade. */
    public static function clean(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        }

        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\0"], ["\n", "\n", ' ', ''], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim($text);
    }
}
