<?php

namespace App\Services\Users;

use Illuminate\Http\Request;

/** Les choix faits sur la page Utilisateurs du super administrateur : le segment, les filtres et le tri, tous dans l'adresse. */
final class UserFilters
{
    /** Chaque segment répond à une question de l'équipe : qui aider, qui relancer, qui est sur le point de partir. */
    public const SEGMENTS = [
        'tous' => ['Tous', 'Toutes les personnes inscrites.'],
        'nouveaux' => ['Nouveaux', 'Inscrits depuis moins d\'une semaine : le bon moment pour se présenter.'],
        'profil' => ['Profil à compléter', 'Arrivés par Google, Apple... mais arrêtés avant d\'avoir donné leur entreprise et leur numéro.'],
        'sans_assistant' => ['Sans assistant', 'Inscrits, mais n\'ont rien créé : proposez de démarrer avec eux.'],
        'sans_connaissances' => ['Assistant vide', 'Ont créé un assistant sans lui donner de connaissances : il ne sait encore rien répondre.'],
        'a_tester' => ['À tester', 'L\'assistant a ses connaissances mais n\'a jamais été essayé.'],
        'pas_en_ligne' => ['Pas encore en ligne', 'Ont testé leur assistant, mais aucun vrai client ne lui a encore écrit.'],
        'en_ligne' => ['En ligne, pas encore payants', 'De vrais clients leur écrivent : le moment de parler d\'une offre.'],
        'essai_fin' => ['Essai bientôt fini', 'L\'essai gratuit se termine dans moins de 7 jours.'],
        'essai_expire' => ['Essai terminé', 'L\'assistant est en pause : le client doit choisir une offre.'],
        'dormants' => ['Dormants', 'Pas de connexion depuis plus de 14 jours : à réveiller avant qu\'ils oublient.'],
        'payants' => ['Payants', 'Ont une offre payante.'],
        'a_relancer' => ['À relancer', 'Une relance est prévue aujourd\'hui ou était prévue avant.'],
        'interesses' => ['Intéressés', 'Marqués « intéressé » ou « en discussion ».'],
        'stop' => ['Ne plus contacter', 'Ont demandé à ne plus être contactés : aucun message de l\'équipe.'],
    ];

    public const SOURCES = ['email' => 'E-mail', 'google' => 'Google', 'apple' => 'Apple', 'microsoft' => 'Microsoft', 'facebook' => 'Facebook'];

    public const STATES = ['actif' => 'Actif', 'desactive' => 'Désactivé', 'suspendu' => 'Espace suspendu'];

    public const CRM = [
        'nouveau' => 'Nouveau',
        'contacte' => 'Contacté',
        'interesse' => 'Intéressé',
        'en_discussion' => 'En discussion',
        'client' => 'Client',
        'perdu' => 'Perdu',
        'stop' => 'Ne plus contacter',
    ];

    public const SORTS = ['recent' => 'Plus récents', 'activite' => 'Dernière activité', 'nom' => 'Nom', 'relance' => 'Prochaine relance'];

    public function __construct(
        public readonly string $segment = 'tous',
        public readonly string $q = '',
        public readonly ?string $source = null,
        public readonly ?string $plan = null,
        public readonly ?string $state = null,
        public readonly ?string $crm = null,
        public readonly ?int $owner = null,
        public readonly string $sort = 'recent',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $pick = fn (string $key, array $allowed) => in_array($request->query($key), $allowed, true) ? $request->query($key) : null;

        return new self(
            segment: $pick('segment', array_keys(self::SEGMENTS)) ?? 'tous',
            q: mb_substr(trim((string) $request->query('q')), 0, 80),
            source: $pick('source', array_keys(self::SOURCES)),
            plan: preg_match('/^[a-z0-9_-]{1,40}$/', (string) $request->query('offre')) ? (string) $request->query('offre') : null,
            state: $pick('etat', array_keys(self::STATES)),
            crm: $pick('statut', array_keys(self::CRM)),
            owner: $request->integer('responsable') ?: null,
            sort: $pick('tri', array_keys(self::SORTS)) ?? 'recent',
        );
    }

    /** @param array<string,mixed> $changes */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** @return array<string,mixed> */
    public function toQuery(): array
    {
        return array_filter([
            'segment' => $this->segment !== 'tous' ? $this->segment : null,
            'q' => $this->q !== '' ? $this->q : null,
            'source' => $this->source,
            'offre' => $this->plan,
            'etat' => $this->state,
            'statut' => $this->crm,
            'responsable' => $this->owner,
            'tri' => $this->sort !== 'recent' ? $this->sort : null,
        ], fn ($v) => $v !== null);
    }

    public function isFiltered(): bool
    {
        return $this->q !== '' || $this->source !== null || $this->plan !== null || $this->state !== null || $this->crm !== null
            || $this->owner !== null || $this->sort !== 'recent';
    }
}
