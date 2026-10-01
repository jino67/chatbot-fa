<?php

namespace App\Import;

/**
 * Retire d'un texte ce qui identifie une personne avant tout enregistrement : numéros de téléphone, adresses e-mail,
 * suites de chiffres (comptes, références), liens avec identifiants et noms des autres participants de la discussion.
 * Les prix sont conservés : « 18 000 FCFA » n'est jamais pris pour un numéro.
 */
final class Anonymizer
{
    /** @param  list<string>  $names  noms à masquer (les autres personnes de la discussion) */
    public function __construct(private readonly array $names = [], private readonly string $replacement = 'le client') {}

    public function clean(string $text): string
    {
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[e-mail]', $text);

        // Liens : on garde l'adresse du site, sans les paramètres qui peuvent porter un identifiant.
        $text = preg_replace_callback('#https?://[^\s<>()]+#iu', fn ($m) => preg_match('#wa\.me|whatsapp#i', $m[0]) ? '[lien WhatsApp]' : preg_replace('/[?#].*$/', '', $m[0]), $text);

        // Numéros internationaux, puis écrits par groupes de deux chiffres, puis suites de 8 chiffres ou plus.
        $text = preg_replace('/(?<!\d)(?:\+|00)\d[\d\s().\-]{7,17}\d(?!\d)/u', '[numéro]', $text);
        $text = preg_replace('/(?<![\d])(?:\d{2}[\s.\-]){3}\d{2}(?![\d])/u', '[numéro]', $text);
        $text = preg_replace('/(?<![\d,.])\d{8,}(?!\d)(?!\s?(?:FCFA|CFA|XOF|F\b|€|EUR|MAD|DH|\$))/iu', '[numéro]', $text);

        foreach ($this->names as $name) {
            $name = trim($name);
            // Un « nom » qui est en fait un numéro (contact non enregistré) est déjà traité ci-dessus.
            if (mb_strlen($name) >= 3 && ! preg_match('/^[+\d\s]+$/', $name)) {
                $text = preg_replace('/(?<![\p{L}\d])'.preg_quote($name, '/').'(?![\p{L}\d])/iu', $this->replacement, $text);
            }
        }

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $text));
    }
}
