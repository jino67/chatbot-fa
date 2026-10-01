<?php

namespace App\Services;

use App\Chat\InstructionGenerator;
use App\Ingestion\IngestionPipeline;
use App\Models\Bot;
use App\Models\Plan;
use App\Models\Source;
use App\Models\Workspace;
use App\Support\Currency;
use App\Support\SeoPages;

/**
 * L'assistant de la page d'accueil (la « vitrine ») : il présente le produit aux visiteurs. Ses connaissances sont
 * écrites ici à partir des offres en base, des langues et des réglages de la marque, puis relues par le même pipeline
 * que les documents d'un client. Une offre qui change de prix ou de quota met donc l'assistant à jour (sync), au lieu
 * de le laisser annoncer l'ancienne grille : un assistant censé ne jamais inventer un prix ne doit pas se tromper sur le sien.
 *
 * Le texte de chaque source est rédigé comme une réponse à la question qu'un visiteur pose vraiment (« combien ça
 * coûte ? ») : les recherches courtes comme celle-là tombent alors sur la bonne source.
 */
class LandingBot
{
    public const WORKSPACE_SLUG = 'kouma-vitrine';

    public const BOT_NAME = 'Assistant Kouma';

    /** Essais par source : une coupure réseau brève (DNS, connexion) ne doit pas laisser l'assistant sans connaissances. */
    public const ATTEMPTS = 3;

    /** Secondes d'attente avant un nouvel essai (multipliées par le numéro de l'essai) ; 0 dans les tests. */
    public int $retryDelay = 3;

    public function __construct(
        private readonly IngestionPipeline $pipeline,
        private readonly InstructionGenerator $generator,
        private readonly PlatformSettings $settings,
    ) {}

    /** Crée l'assistant s'il manque, met ses connaissances à jour, le désigne comme assistant de la page d'accueil. */
    public function sync(): Bot
    {
        $bot = $this->ensureBot();
        $this->settings->set('marketing.landing_bot_key', $bot->public_key);

        $knowledge = $this->knowledge();
        foreach ($knowledge as $title => $content) {
            $source = Source::withoutGlobalScopes()->firstOrNew(['bot_id' => $bot->id, 'type' => Source::TYPE_TEXT, 'name' => $title]);

            // Une source inchangée n'est pas relue : pas d'appel au fournisseur d'embeddings pour rien.
            if ($source->exists && $source->status === Source::READY && ($source->payload['content'] ?? null) === $content) {
                continue;
            }

            $source->forceFill(['workspace_id' => $bot->workspace_id, 'payload' => ['content' => $content]])->save();
            $this->ingest($source);
        }

        // Une source d'une ancienne version du texte (titre disparu) ne doit plus répondre.
        Source::withoutGlobalScopes()->where('bot_id', $bot->id)->whereNotIn('name', array_keys($knowledge))->get()->each->delete();

        return $bot->fresh();
    }

    /** Lit la source ; si l'échec est technique (réseau, fournisseur), nouvel essai après une courte pause. */
    private function ingest(Source $source): void
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $this->pipeline->run($source);
            $source->refresh();

            if ($source->status !== Source::FAILED || ! str_starts_with((string) $source->error, 'Erreur technique') || $attempt === self::ATTEMPTS) {
                return;
            }

            if ($this->retryDelay > 0) {
                sleep($this->retryDelay * $attempt);
            }
        }
    }

    /** L'assistant de la page d'accueil existe-t-il déjà ? (les mises à jour automatiques ne le créent jamais) */
    public function exists(): bool
    {
        $key = $this->settings->get('marketing.landing_bot_key');

        return $key && Bot::withoutGlobalScopes()->where('public_key', $key)->exists();
    }

    private function ensureBot(): Bot
    {
        $brand = $this->brand();
        $workspace = Workspace::firstOrCreate(['slug' => self::WORKSPACE_SLUG], ['name' => $brand.' (vitrine)', 'plan' => 'business', 'plan_started_at' => now()]);

        $profile = array_replace($this->generator->defaultProfile(), [
            'description' => "Plateforme qui permet à une entreprise de créer son assistant conversationnel à partir de ses documents, photos et liens, pour répondre à ses clients sur son site web et sur WhatsApp.",
            'country' => 'Burkina Faso',
            'offers' => 'Assistant sur site web, WhatsApp, messages vocaux, plusieurs langues, demandes et alertes, tableau de bord.',
            'extra_rules' => "Quand on te demande un prix ou un tarif, donne les prix des offres tels qu'ils figurent dans les extraits, en commençant par l'essai gratuit.\nNe promets aucune fonctionnalité absente des extraits.\nInvite à créer un compte gratuit ou à laisser un contact quand la question dépasse le produit.",
        ]);

        $bot = Bot::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('name', self::BOT_NAME)->first();

        if (! $bot) {
            $instructions = $this->generator->generate(self::BOT_NAME, $brand, 'services', $profile);

            return Bot::create([
                'workspace_id' => $workspace->id,
                'name' => self::BOT_NAME,
                'language' => 'fr',
                'sector' => 'services',
                'profile' => $profile,
                'instructions' => $instructions,
                'instructions_default' => $instructions,
                'welcome_message' => "Bonjour ! Je suis l'assistant de {$brand}. Demandez-moi comment ça marche, combien ça coûte, ou comment brancher WhatsApp.",
                'suggested_questions' => ['Comment ça marche ?', 'Combien ça coûte ?', "Puis-je l'avoir sur WhatsApp ?", 'Mes données sont-elles protégées ?'],
                'theme' => ['color' => '#2340D9', 'position' => 'right', 'title' => $brand],
                'allowed_origins' => [],
            ]);
        }

        // Les règles ont pu changer depuis la création : la consigne suit, sauf si quelqu'un l'a modifiée à la main.
        if ($bot->instructions === $bot->instructions_default) {
            $instructions = $this->generator->generate(self::BOT_NAME, $brand, 'services', $profile);
            $bot->forceFill(['profile' => $profile, 'instructions' => $instructions, 'instructions_default' => $instructions])->save();
        }

        return $bot;
    }

    /* ============================== Connaissances ============================== */

    /** @return array<string,string> titre de la source => contenu */
    public function knowledge(): array
    {
        $brand = $this->brand();
        $plans = Plan::forBusiness()->where('is_public', true)->orderBy('sort')->get();
        $trial = $plans->first(fn (Plan $p) => $p->hasTrial());
        $paid = $plans->reject(fn (Plan $p) => $p->isFree());
        $trialDays = $trial?->trial_days ?? 14;
        $trialLimit = $trial?->limit('messages_per_month');

        $prices = $paid->map(fn (Plan $p) => "{$p->name} à ".$this->price($p).' par mois')->implode(', ');
        $range = $paid->isEmpty() ? '' : 'de '.$this->price($paid->first()).' à '.$this->price($paid->last()).' par mois';
        $contact = $this->contact();
        $developers = route('developers');
        $resources = route('seo.hub');

        $knowledge = [
            'Comment ça marche' => "# Comment ça marche\nOn crée son assistant en trois étapes : on choisit son métier, on lui donne ses documents (PDF, Word, tableurs), des photos d'affiches ou de menus, le lien de son site ou du texte, puis on l'installe sur son site avec une ligne de code ou sur WhatsApp. On le teste dans l'espace client avant de le publier.\n\nL'assistant répond aux clients uniquement à partir de ces contenus. Quand il ne sait pas, il le dit et propose de contacter l'équipe ; la question apparaît dans la liste des questions sans réponse, pour que l'entreprise la complète en une minute. Quand le client demande une personne, l'entreprise est prévenue tout de suite (par e-mail ou sur WhatsApp, au choix) et reprend la conversation depuis sa boîte de réception.\n\nL'entreprise peut modifier à tout moment la consigne de son assistant : ton, tutoiement ou vouvoiement, règles du métier, informations à toujours donner.",

            // Une question par source, courte : les questions courtes des visiteurs trouvent ainsi la bonne réponse.
            'Prix et tarifs' => "# Prix et tarifs\nQuestion : combien ça coûte ? quel est le prix ? quels sont les tarifs ? c'est combien ?\nRéponse : on démarre par un essai gratuit de {$trialDays} jours, sans carte bancaire".($trialLimit ? " (jusqu'à {$trialLimit} réponses)" : '').". Ensuite, les offres payantes vont {$range}, selon le nombre d'assistants, de réponses et de messages WhatsApp : {$prices}.",

            'Offre gratuite' => "# Offre gratuite et essai\nQuestion : y a-t-il une offre gratuite ? c'est gratuit ? puis-je essayer avant de payer ?\nRéponse : oui, l'essai gratuit dure {$trialDays} jours, sans carte bancaire et sans engagement. À la fin de l'essai, l'assistant se met en pause et les contenus sont conservés jusqu'au choix d'une offre.",

            'Engagement' => "# Engagement\nQuestion : y a-t-il un engagement ? puis-je arrêter quand je veux ?\nRéponse : non, il n'y a aucun engagement : on change d'offre ou on arrête quand on veut.",

            'Offres en détail' => "# Offres et limites\n".$plans->map(fn (Plan $p) => $this->planLine($p))->implode("\n"),

            'Comment payer' => "# Comment payer\nQuestion : comment payer ? quels moyens de paiement ? puis-je payer par Orange Money ou Moov Money ?\nRéponse : par Orange Money, Moov Money, Coris Money ou virement. L'entreprise envoie la référence du paiement et l'offre est activée dès réception. Aucune carte bancaire n'est demandée.",

            'Monnaies' => "# Monnaies\nQuestion : en quelle monnaie sont les prix ? puis-je payer en euro ou en franc comorien ?\nRéponse : les prix sont affichés en FCFA, en franc comorien, en euro, en dollar ou en dirham marocain, au choix du visiteur.",

            'Messages WhatsApp payants' => "# Messages WhatsApp : faut-il payer en plus ?\nQuestion : les messages WhatsApp sont-ils payants en plus ? faut-il un compte Meta ?\nRéponse : non. Un volume mensuel de messages WhatsApp est inclus dans les offres avec WhatsApp, sans compte à ouvrir chez Meta ni carte bancaire. Si le volume est dépassé, on recharge des messages par Mobile Money.",

            'WhatsApp' => "# WhatsApp\nL'assistant peut répondre sur un numéro WhatsApp Business. L'activation est faite avec l'équipe de {$brand} : compte WhatsApp Business, vérification du numéro, configuration. L'entreprise garde la propriété de son numéro. Les conversations arrivent dans la boîte de réception ; quand l'entreprise répond elle-même, l'assistant se tait pour cette conversation.\n\nWhatsApp n'autorise le texte libre que dans les 24 heures qui suivent le dernier message du client. Au-delà, il faut un modèle de message approuvé : la plateforme permet de les créer, de suivre leur approbation et de les envoyer (offres Pro et Business). Les offres sans WhatsApp incluent le widget de site web.",

            'Langues et messages vocaux' => "# Langues et messages vocaux\n".$this->languagesText()."\n\nLes clients peuvent envoyer des messages vocaux, sur WhatsApp comme sur le site web : l'assistant les écoute et répond par écrit, ou en audio pour le français, l'anglais et l'arabe. Un volume mensuel de messages vocaux est inclus selon l'offre (à partir de l'offre « Bon plan »).",

            'Demandes, commandes et alertes' => "# Demandes, commandes et alertes\nL'assistant reconnaît une commande, une demande de rendez-vous, une demande de devis ou une demande de parler à une personne. Il prépare la demande avec les coordonnées du client et prévient l'entreprise. Toutes les demandes arrivent dans la page « Demandes ».\n\nL'entreprise choisit comment être prévenue : par e-mail, par un message WhatsApp sur son numéro, sur celui de chaque membre de l'équipe, ou sur un autre numéro de son choix, avec un rappel si personne ne répond. Les commandes et les rendez-vous sont toujours confirmés par l'entreprise, jamais par le robot.",

            'Sources de connaissances' => "# Sources de connaissances\nDocuments PDF, Word, texte, tableurs Excel et CSV (un catalogue de produits, une carte ou une liste de prix est comprise : noms, prix, disponibilité, catégories ; un modèle de catalogue est à télécharger, et l'export du catalogue WhatsApp Business de Meta est lu tel quel), photos (le texte des affiches et des menus est lu), pages de site web (relues automatiquement chaque jour ou chaque semaine), textes collés, questions et réponses. Pour une page Facebook ou Instagram, le client colle le lien puis le contenu de sa page : Meta interdit la lecture automatique de ces pages ; une connexion officielle est possible pour ceux qui l'administrent.\n\nAvec l'offre Pro (ou en option), l'entreprise peut importer une discussion WhatsApp exportée : l'assistant apprend son style et ses réponses habituelles. Le fichier n'est pas conservé et les données personnelles des clients sont masquées.",

            'Données et sécurité' => "# Données et sécurité\nChaque entreprise a un espace isolé : ses documents ne servent qu'à son assistant. Les clés d'accès sont chiffrées, les échanges passent par HTTPS et l'entreprise peut supprimer ses contenus à tout moment. Pour produire une réponse, les extraits pertinents et la question sont envoyés à un fournisseur d'intelligence artificielle.",

            'Application sur téléphone' => "# Installer {$brand} sur son téléphone\nL'espace client s'installe comme une application. Sur iPhone et iPad : ouvrir le site dans Safari, toucher Partager, puis « Sur l'écran d'accueil ». Sur Android : menu du navigateur, puis « Installer l'application ». Une icône apparaît avec les autres applications, sans rien télécharger.",

            'Accompagnement et contact' => "# Accompagnement et contact\nPour ceux qui ne veulent pas s'en occuper, l'équipe de {$brand} gère tout : elle crée l'assistant, lit les documents, active WhatsApp et met à jour les tarifs. Le devis est clair et sans engagement.".($contact ? "\n\nContact : {$contact}." : '')."\n\nUne offre pour développeurs permet d'appeler l'assistant depuis sa propre application : tout est expliqué sur la page {$developers}. Des guides pratiques (prix d'un chatbot WhatsApp, créer son assistant, métiers et pays) sont réunis sur {$resources}.",
        ];

        return $knowledge + $this->sitePages();
    }

    /**
     * Les pages de contenu du site (solutions, guides, métiers, pays) : l'assistant connaît ce que le site dit, avec la
     * même source que les pages (config/seo.php, prix et quotas lus dans les offres), sans lire du HTML. Chaque page
     * est une source, avec son adresse pour que l'assistant puisse y renvoyer le visiteur.
     *
     * @return array<string,string>
     */
    private function sitePages(): array
    {
        $seo = app(SeoPages::class);
        $pages = [];

        foreach (array_keys(SeoPages::all()) as $key) {
            $page = $seo->page($key);
            $text = ['# '.$page['h1'], $page['lead']];
            if ($page['facts']) {
                $text[] = collect($page['facts'])->map(fn ($value, $label) => "{$label} : {$value}")->implode("
");
            }
            foreach ($page['sections'] as $section) {
                $text[] = '## '.$section['title'];
                $lines = array_merge($section['text'], array_map(fn ($item) => '- '.$item, $section['list']), array_map(fn ($step, $i) => ($i + 1).'. '.$step, $section['steps'], array_keys($section['steps'])));
                $text[] = implode("
", $lines);
            }
            foreach ($page['faq'] as [$question, $answer]) {
                $text[] = "Question : {$question}
Réponse : {$answer}";
            }
            $text[] = 'Page du site : '.$page['url'];

            $pages['Page : '.$page['label']] = implode("

", array_filter($text));
        }

        return $pages;
    }

    private function planLine(Plan $p): string
    {
        $others = collect(Currency::formatPrices($p->prices ?? []))->except('XOF')->implode(' ou ');
        $features = collect(Plan::featureFields())->filter(fn ($f) => $p->feature($f['key']))->pluck('label')->implode(', ');
        $whatsapp = $p->limit('whatsapp_messages_per_month');
        $voice = $p->limit('voice_per_month');

        return "{$p->name} : ".($p->isFree() ? 'gratuit' : $this->price($p).' par mois'.($others ? " (soit {$others})" : '')).'. '
            .($p->tagline ? rtrim($p->tagline, '.').'. ' : '')
            ."{$p->limit('bots')} assistant(s), {$p->limit('messages_per_month')} réponses par mois, {$p->limit('sources')} sources, {$p->limit('pages_per_crawl')} pages lues par site, {$p->limit('members')} utilisateur(s)"
            .($whatsapp ? ", {$whatsapp} messages WhatsApp par mois" : '')
            .($voice ? ", {$voice} messages vocaux par mois" : '')
            .'.'.($features ? " Inclus : {$features}." : '');
    }

    private function languagesText(): string
    {
        $tiers = ['native' => [], 'good' => [], 'assisted' => [], 'experimental' => []];
        foreach ((array) config('languages') as $code => $language) {
            if (is_array($language) && isset($language['tier'], $tiers[$language['tier']])) {
                $tiers[$language['tier']][] = $language['name'];
            }
        }
        $list = fn (array $names) => implode(', ', $names);

        return "L'assistant répond dans la langue du client, parmi celles que l'entreprise a choisies. Langues bien maîtrisées : ".$list($tiers['native']).($tiers['good'] ? ' et '.$list($tiers['good']) : '').'.'
            .($tiers['assisted'] ? ' Langues locales « assistées » (phrases courtes et simples, à tester avec ses propres phrases) : '.$list($tiers['assisted']).'.' : '')
            .($tiers['experimental'] ? ' Langues expérimentales (très peu de données, l\'assistant répond aussi en français) : '.$list($tiers['experimental']).'.' : '');
    }

    private function price(Plan $plan): string
    {
        return Currency::format($plan->priceIn('XOF') ?? 0, 'XOF');
    }

    private function contact(): string
    {
        $brand = $this->settings->brand();

        return collect([
            $brand['email'] ? 'e-mail '.$brand['email'] : null,
            $brand['whatsapp'] ? 'WhatsApp +'.$brand['whatsapp'] : null,
        ])->filter()->implode(', ');
    }

    private function brand(): string
    {
        return $this->settings->brand()['name'];
    }
}
