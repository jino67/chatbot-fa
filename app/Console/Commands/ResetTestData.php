<?php

namespace App\Console\Commands;

use App\Services\TestDataReset;
use Illuminate\Console\Command;

/**
 * Remise à zéro des données d'essai en ligne de commande. Sans --force, la commande ne fait que dire ce qu'elle effacerait.
 */
class ResetTestData extends Command
{
    protected $signature = 'platform:reset-test-data
        {--workspace=* : numéro de chaque espace concerné (colonne « Espace » de la page Données de test)}
        {--payments : effacer les paiements enregistrés}
        {--credit : remettre le crédit de messages WhatsApp à 0}
        {--plan : remettre l\'espace sur l\'offre Découverte}
        {--usage : effacer la consommation mesurée}
        {--requests : effacer les demandes d\'offre}
        {--force : faire vraiment l\'effacement (sans cette option, simulation)}';

    protected $description = 'Remet à zéro les paiements simulés et les compteurs des espaces d\'essai';

    private const HEADERS = ['Espace', 'Nom', 'Offre', 'Paiements', 'Crédit WhatsApp', 'Mesures', 'Demandes'];

    public function handle(TestDataReset $reset): int
    {
        $ids = array_map('intval', (array) $this->option('workspace'));
        $options = array_keys(array_filter([
            'payments' => $this->option('payments'), 'credit' => $this->option('credit'), 'plan' => $this->option('plan'),
            'usage' => $this->option('usage'), 'requests' => $this->option('requests'),
        ]));

        if ($ids === [] || $options === []) {
            $this->warn('Désignez au moins un espace (--workspace=ID) et une chose à remettre à zéro (--payments, --credit, --plan, --usage, --requests).');
            $this->table(self::HEADERS, $this->rows($reset->overview()));

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->info("Simulation : rien n'est effacé. Ajoutez --force pour le faire.");
            $this->table(self::HEADERS, $this->rows(array_values(array_filter($reset->overview(), fn ($r) => in_array($r['workspace']->id, $ids, true)))));

            return self::SUCCESS;
        }

        $result = $reset->reset($ids, $options);
        $this->info($result['workspaces'].' espace(s) remis à zéro. Copie de ce qui a disparu : storage/app/'.$result['backup']);
        $this->line((string) json_encode($result['counts']));

        return self::SUCCESS;
    }

    /** @param list<array<string,mixed>> $overview */
    private function rows(array $overview): array
    {
        return array_map(fn ($r) => [$r['workspace']->id, $r['workspace']->name, $r['workspace']->plan, $r['payments'], $r['credit'], $r['usage'], $r['requests']], $overview);
    }
}
