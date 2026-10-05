<?php

namespace App\Chat;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmRequest;
use App\Ai\Llm\LlmRouter;
use App\Ai\LlmException;
use App\Support\Text;

/**
 * Fabrique la consigne initiale d'un assistant a partir du secteur d'activite et du profil de l'entreprise :
 * role, mission, ton, informations de reference, parcours guides (commande, rendez-vous, devis...),
 * regles metier, interdits, criteres de transfert vers un humain, exemples de forme.
 * Le resultat est un texte ordinaire que le client relit et modifie a volonte.
 *
 * La generation est deterministe et gratuite ; une amelioration par IA est proposee en option (polish).
 */
class InstructionGenerator
{
    public const TONES = ['chaleureux' => 'Chaleureux', 'professionnel' => 'Professionnel', 'decontracte' => 'Décontracté'];

    public const FORMALITY = ['vous' => 'Vouvoiement', 'tu' => 'Tutoiement'];

    public const EMOJIS = ['none' => 'Aucun', 'light' => 'Quelques-uns'];

    public const LENGTHS = ['short' => 'Très courtes', 'balanced' => 'Équilibrées', 'detailed' => 'Détaillées'];

    public function __construct(private readonly LlmClient $llm) {}

    /** @return array<string,array<string,mixed>> */
    public function sectors(): array
    {
        return config('sectors');
    }

    public function sector(?string $slug): array
    {
        return config('sectors.'.($slug ?: 'autre')) ?? config('sectors.autre');
    }

    /** @return array<string,mixed> */
    public function defaultProfile(): array
    {
        return [
            'description' => '', 'city' => '', 'country' => '', 'hours' => '', 'phone' => '', 'email' => '', 'website' => '',
            'offers' => '', 'extra_rules' => '',
            'tone' => 'chaleureux', 'formality' => 'vous', 'emojis' => 'light', 'length' => 'balanced', 'languages' => ['fr'],
        ];
    }

    /** @param array<string,mixed> $profile */
    public function generate(string $assistant, string $company, ?string $sector, array $profile): string
    {
        $profile = array_replace($this->defaultProfile(), array_filter($profile, fn ($v) => $v !== null && $v !== ''));
        $s = $this->sector($sector);
        $tu = $profile['formality'] === 'tu';

        $where = trim(implode(', ', array_filter([$profile['city'], $profile['country']])));
        $intro = "Tu es {$assistant}, l'assistant virtuel de {$company}, {$s['nature']}".($where ? " situé à {$where}" : '').'.';
        if (trim((string) $profile['description']) !== '') {
            $intro .= ' '.rtrim(trim($profile['description']), '.').'.';
        }
        $intro .= "\nTu parles au nom de {$company}, auprès de ses clients, sur son site web et sur WhatsApp, à toute heure.";

        $sections = [];
        $sections[] = "## Rôle\n".$intro;
        $sections[] = "## Ta mission\n".$this->bullets($s['objectifs']);
        $sections[] = "## Ton et style\n".$this->bullets($this->styleLines($profile, $tu));

        if ($facts = $this->facts($profile)) {
            $sections[] = "## Informations de référence sur l'entreprise\n".$this->bullets($facts)
                ."\nCes informations valent même sans extrait. Pour tout détail (prix, disponibilité, conditions), appuie-toi sur les extraits.";
        }

        $sections[] = "## Parcours à suivre\n".$this->bullets($s['parcours'])."\n".$this->bullets([
            'Coordonnées : sur WhatsApp, le numéro du client est déjà connu, ne le demande jamais. Sur le site web, demande un numéro de téléphone (de préférence WhatsApp) avant de conclure.',
            'Ne redemande jamais une information que le client a déjà donnée dans la conversation.',
            "Quand le client répond à tes questions (nom, quartier, paiement), poursuis le parcours : ce ne sont pas des questions sur l'entreprise.",
        ]);

        $sections[] = "## Conseil et vente\n".$this->bullets(array_merge([
            'Comprends avant de proposer : une seule question ciblée quand le besoin est flou, jamais un questionnaire.',
            'Recommande peu, mais bien : un ou deux choix précis avec la raison, plutôt qu\'une liste complète.',
            'Rassure : rappelle ce qui est inclus ou garanti seulement si les extraits le disent.',
            'Termine par un pas concret (voir une photo, commander, réserver) sans insister.',
        ], $s['conseil'] ?? []));

        $sections[] = "## Photos envoyées par le client\nLe client peut t'envoyer une photo : elle te parvient décrite. Selon le métier :\n".$this->bullets(array_merge($s['images'] ?? [], [
            'Ne prétends jamais voir ce que la description ne mentionne pas ; si la photo est floue ou sans rapport, demande poliment une autre photo.',
        ]));

        $rules = array_merge($s['regles'], $this->lines((string) $profile['extra_rules']));
        $sections[] = "## Règles propres à ce métier\n".$this->bullets($rules);

        $sections[] = "## Ce que tu ne fais jamais\n".$this->bullets(array_merge($s['interdits'], [
            'Tu n\'inventes jamais un prix, un délai, une disponibilité, une adresse ou une politique.',
            'Tu ne demandes jamais un code PIN, un code de vérification (OTP), un mot de passe ni un numéro de carte, même pour un paiement Mobile Money. Si un client veut te les donner, dissuade-le et rappelle-lui de ne jamais partager ces codes.',
            'Tu ne critiques jamais un concurrent, un client ou un membre de l\'équipe.',
            'Tu ne partages aucune information sur d\'autres clients.',
        ]));

        $handoff = array_merge(
            ['Le client demande à parler à une personne, s\'impatiente ou se plaint.'],
            $s['transfert'],
            ['L\'information demandée n\'est pas dans les extraits et la demande est importante pour le client.'],
        );
        $contact = $profile['phone'] ? "communique le {$profile['phone']} ou explique" : 'explique';
        $sections[] = "## Quand passer la main à l'équipe\nTermine ta réponse par le marqueur de transfert dans les cas suivants :\n".$this->bullets($handoff)
            ."\nEn passant la main, {$contact} qu'un membre de l'équipe répond dans cette conversation, et réconforte le client en une phrase.";

        $sections[] = "## Exemples de forme\nCes exemples montrent le style attendu. Les faits (prix, horaires, lieux) viennent TOUJOURS des extraits, jamais de ces exemples. Ne les recopie pas.\n\n"
            .implode("\n\n", array_map(fn ($pair) => $pair[0]."\n".$pair[1], $s['exemples_forme']));

        return implode("\n\n", $sections);
    }

    /** @param array<string,mixed> $profile */
    public function welcome(string $assistant, string $company, array $profile): string
    {
        $tu = ($profile['formality'] ?? 'vous') === 'tu';

        return match ($profile['tone'] ?? 'chaleureux') {
            'professionnel' => "Bonjour, je suis {$assistant}, l'assistant de {$company}. ".($tu ? 'Que puis-je faire pour toi ?' : 'Que puis-je faire pour vous ?'),
            'decontracte' => ($tu ? 'Salut !' : 'Bonjour !')." Moi, c'est {$assistant}, de chez {$company}. ".($tu ? 'Dis-moi ce qu\'il te faut.' : 'Dites-moi ce qu\'il vous faut.'),
            default => "Bonjour et bienvenue chez {$company} ! Je suis {$assistant}. ".($tu ? 'Comment puis-je t\'aider ?' : 'Comment puis-je vous aider ?'),
        };
    }

    /** @return list<string> */
    public function suggestions(?string $sector): array
    {
        return array_slice($this->sector($sector)['questions'], 0, 4);
    }

    /**
     * Amelioration par IA : reformule et complete la consigne sans ajouter de faits. Renvoie la consigne
     * d'origine si aucun modele n'est disponible ou si le resultat n'est pas exploitable.
     *
     * @param  array<string,mixed>  $profile
     */
    public function polish(string $draft, string $company, ?string $sector, array $profile): string
    {
        if ($this->llm instanceof LlmRouter && $this->llm->isOffline()) {
            return $draft;
        }

        $system = "Tu es un expert en conception d'assistants conversationnels pour de petites entreprises d'Afrique francophone. "
            ."On te donne la consigne d'un assistant. Améliore-la : rends-la plus précise, plus naturelle pour le métier et l'entreprise décrits, "
            .'ajoute des parcours et des exemples de forme pertinents, corrige les ambiguïtés. '
            ."Règles strictes : conserve toutes les sections (rôle, mission, ton, parcours, conseil et vente, photos du client, règles, interdits, passage de main, exemples) et tous les interdits ; garde la règle sur le numéro de téléphone (jamais demandé sur WhatsApp, demandé sur le site web) ; n'ajoute AUCUN fait sur l'entreprise (prix, horaires, adresses) qui ne figure pas dans la consigne ; "
            .'garde le tutoiement ou le vouvoiement demandé ; reste sous 1400 mots (la consigne ne doit pas dépasser 11 000 caractères) ; réponds uniquement par la consigne améliorée, en français, sans commentaire.';

        $context = 'Entreprise : '.$company.'. Secteur : '.$this->sector($sector)['label'].'.';

        try {
            $response = $this->llm->complete(new LlmRequest($system, [['role' => 'user', 'content' => $context."\n\nConsigne actuelle :\n\n".$draft]], maxTokens: 2500));
        } catch (LlmException) {
            return $draft;
        }

        $text = trim($response->text);

        return (! $response->refused && mb_strlen($text) > 400 && mb_strlen($text) < 11500 && str_contains($text, '## ')) ? Text::clean($text) : $draft;
    }

    /** @param array<string,mixed> $profile @return list<string> */
    private function styleLines(array $profile, bool $tu): array
    {
        $tone = match ($profile['tone']) {
            'professionnel' => 'Ton professionnel, courtois et précis, sans familiarité.',
            'decontracte' => 'Ton décontracté et amical, direct, sans jargon, avec des phrases courtes.',
            default => 'Ton chaleureux et attentionné, comme un bon commerçant qui connaît ses clients : jamais froid, jamais robotique.',
        };
        $address = $tu
            ? 'Tu tutoies le client, avec respect. Si le client te vouvoie, tu peux continuer à le tutoyer sauf s\'il te le demande.'
            : 'Tu vouvoies toujours le client, y compris s\'il te tutoie.';
        $emojis = $profile['emojis'] === 'none'
            ? 'N\'utilise aucun émoji.'
            : 'Tu peux utiliser un émoji discret de temps en temps (un par message au maximum), jamais à côté d\'un prix, d\'un horaire ou d\'une information sensible.';
        $length = match ($profile['length']) {
            'short' => 'Réponses très courtes : une à trois phrases.',
            'detailed' => 'Réponses complètes mais structurées : jamais plus de huit lignes.',
            default => 'Réponses de deux à cinq phrases, ou une liste courte.',
        };
        // La liste des langues parlées se règle dans les paramètres de l'assistant (règle de la plateforme) : la consigne
        // n'en cite aucune, pour ne pas se contredire quand le client change ses langues.
        $languages = 'Si le message mélange plusieurs langues, réponds dans la langue principale du message.';

        return [$tone, $address, $emojis, $length, $languages, 'Tu te présentes uniquement dans le premier message, jamais ensuite.'];
    }

    /** @param array<string,mixed> $profile @return list<string> */
    private function facts(array $profile): array
    {
        $facts = [];
        foreach ([
            'hours' => 'Horaires',
            'phone' => 'Téléphone ou WhatsApp',
            'email' => 'E-mail',
            'website' => 'Site web',
            'offers' => 'Produits et services principaux',
        ] as $key => $label) {
            if (trim((string) $profile[$key]) !== '') {
                $facts[] = $label.' : '.trim($profile[$key]);
            }
        }

        return $facts;
    }

    /** @param list<string> $items */
    private function bullets(array $items): string
    {
        return implode("\n", array_map(fn ($i) => '- '.$i, array_values(array_filter($items))));
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));
    }
}
