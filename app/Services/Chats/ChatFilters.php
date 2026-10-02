<?php

namespace App\Services\Chats;

use App\Services\Analytics\Tracker;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Les choix faits sur la page Conversations du super administrateur : la vue (tout, l'assistant de Kouma, les assistants des
 * clients, ce qui est à surveiller...), la période, et les filtres fins. Tout vient de l'adresse de la page : on peut donc
 * partager ou enregistrer une recherche. Chaque valeur est validée ici, une seule fois.
 */
final class ChatFilters
{
    public const VIEWS = [
        'toutes' => 'Toutes',
        'kouma' => 'Assistant de Kouma',
        'clients' => 'Assistants des clients',
        'surveiller' => 'À surveiller',
        'prospects' => 'Prospects',
        'questions' => 'Questions sans réponse',
        'entreprises' => 'Par client',
    ];

    /** Les vues qui listent des conversations (les autres sont des synthèses). */
    public const LISTING = ['toutes', 'kouma', 'clients', 'surveiller', 'prospects'];

    public const PERIODS = [1 => '24 heures', 7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '12 mois'];

    public const CHANNELS = ['web' => 'Site web', 'whatsapp' => 'WhatsApp', 'api' => 'Application (API)', 'facebook' => 'Facebook', 'playground' => 'Zone de test'];

    public const STATUSES = ['bot' => 'L\'assistant répond', 'needs_human' => 'Attend une personne', 'human' => 'Une personne a la main', 'closed' => 'Terminée'];

    /** Les signaux qui font remonter une conversation (voir ChatQuery::flag). */
    public const FLAGS = [
        'attente' => 'Client qui attend une personne',
        'sans_reponse' => 'Deux questions sans réponse ou plus',
        'mecontent' => 'Client mécontent (avis négatif ou plainte)',
        'non_servi' => 'Client non servi (assistant en pause ou volume atteint)',
        'erreur' => 'Panne ou refus de l\'IA',
        'prospect' => 'Prospect (contact laissé, demande ou intérêt commercial)',
        'signale' => 'Signalée par l\'équipe',
    ];

    public const SORTS = ['recent' => 'Plus récentes', 'longues' => 'Plus longues', 'anciennes' => 'Plus anciennes'];

    public function __construct(
        public readonly string $view = 'toutes',
        public readonly int $days = 30,
        public readonly ?int $workspace = null,
        public readonly ?int $bot = null,
        public readonly ?string $channel = null,
        public readonly ?string $status = null,
        public readonly ?string $flag = null,
        public readonly string $q = '',
        public readonly bool $withTests = false,
        public readonly string $sort = 'recent',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $pick = fn (string $key, array $allowed) => in_array($request->query($key), $allowed, true) ? $request->query($key) : null;
        $days = (int) $request->query('jours');

        return new self(
            view: $pick('vue', array_keys(self::VIEWS)) ?? 'toutes',
            days: array_key_exists($days, self::PERIODS) ? $days : 30,
            workspace: $request->integer('client') ?: null,
            bot: $request->integer('assistant') ?: null,
            channel: $pick('canal', array_keys(self::CHANNELS)),
            status: $pick('statut', array_keys(self::STATUSES)),
            flag: $pick('signal', array_keys(self::FLAGS)),
            q: mb_substr(trim((string) $request->query('q')), 0, 80),
            withTests: $request->boolean('tests'),
            sort: $pick('tri', array_keys(self::SORTS)) ?? 'recent',
        );
    }

    /** Le début de la période, dans le fuseau de la plateforme. */
    public function from(): CarbonImmutable
    {
        return $this->days === 1
            ? CarbonImmutable::now()->subDay()
            : CarbonImmutable::now(Tracker::timezone())->startOfDay()->subDays($this->days - 1)->setTimezone(config('app.timezone'));
    }

    /** Une copie avec d'autres valeurs (pour les liens des onglets et des puces). @param array<string,mixed> $changes */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** Les paramètres d'adresse de cette recherche (seulement ceux qui ne sont pas les valeurs par défaut). @return array<string,mixed> */
    public function toQuery(): array
    {
        return array_filter([
            'vue' => $this->view !== 'toutes' ? $this->view : null,
            'jours' => $this->days !== 30 ? $this->days : null,
            'client' => $this->workspace,
            'assistant' => $this->bot,
            'canal' => $this->channel,
            'statut' => $this->status,
            'signal' => $this->flag,
            'q' => $this->q !== '' ? $this->q : null,
            'tests' => $this->withTests ? 1 : null,
            'tri' => $this->sort !== 'recent' ? $this->sort : null,
        ], fn ($v) => $v !== null);
    }

    /** Vrai quand un filtre fin (autre que la vue et la période) est posé : l'écran propose alors de tout effacer. */
    public function isFiltered(): bool
    {
        return $this->workspace !== null || $this->bot !== null || $this->channel !== null || $this->status !== null
            || $this->flag !== null || $this->q !== '' || $this->withTests || $this->sort !== 'recent';
    }
}
