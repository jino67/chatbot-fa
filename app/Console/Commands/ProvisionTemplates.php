<?php

namespace App\Console\Commands;

use App\Channels\WhatsApp\TemplateLibrary;
use App\Channels\WhatsApp\TemplateProvisioner;
use App\Models\Bot;
use App\Models\Channel;
use Illuminate\Console\Command;

/**
 * Soumet les modèles de la bibliothèque à WhatsApp pour le canal d'un assistant : un paquet entier (--pack) ou des
 * modèles choisis (--cle). Sert à un essai de bout en bout (compte Twilio ou Meta de test) sans passer par l'écran.
 */
class ProvisionTemplates extends Command
{
    protected $signature = 'whatsapp:templates
        {bot? : Identifiant de l\'assistant}
        {--pack= : essentiel, boutique, restaurant, rendez_vous, hotel ou services}
        {--cle=* : Un ou plusieurs modèles précis}
        {--langue= : fr ou en (par défaut, la langue de l\'assistant)}
        {--liste : Affiche la bibliothèque et les paquets}';

    protected $description = 'Crée les modèles WhatsApp de la bibliothèque sur le canal d\'un assistant et les soumet à approbation';

    public function handle(TemplateLibrary $library, TemplateProvisioner $provisioner): int
    {
        if ($this->option('liste')) {
            return $this->show($library);
        }

        $bot = Bot::withoutGlobalScopes()->find((int) $this->argument('bot'));
        if (! $bot) {
            $this->error('Assistant introuvable : indiquez son identifiant (php artisan whatsapp:templates 1 --pack=essentiel).');

            return self::FAILURE;
        }

        $channel = Channel::withoutGlobalScopes()->where('bot_id', $bot->id)
            ->whereIn('type', [Channel::WHATSAPP_META, Channel::WHATSAPP_TWILIO])->where('status', Channel::ACTIVE)->latest()->first();
        if (! $channel) {
            $this->error("Cet assistant n'a pas de canal WhatsApp actif.");

            return self::FAILURE;
        }

        $keys = (array) $this->option('cle');
        if ($pack = $this->option('pack')) {
            if (! isset($library->packs()[$pack])) {
                $this->error('Paquet inconnu. Disponibles : '.implode(', ', array_keys($library->packs())).'.');

                return self::FAILURE;
            }
            $keys = [...$keys, ...$library->packKeys($pack)];
        }
        if ($keys === []) {
            $this->error('Choisissez un paquet (--pack=essentiel) ou des modèles (--cle=rdv_rappel).');

            return self::FAILURE;
        }

        $language = $this->option('langue') ?: $library->languageFor($bot->language);
        $this->info("Canal {$channel->type} de « {$bot->name} » : ".count(array_unique($keys)).' modèle(s) en '.$language.'.');

        $result = $provisioner->provision($channel, $bot, $keys, $language);

        $this->line('Envoyés à approbation : '.($result['created'] === [] ? 'aucun' : implode(', ', $result['created'])));
        if ($result['existing'] !== []) {
            $this->line('Déjà présents : '.implode(', ', $result['existing']));
        }
        if ($result['skipped'] !== []) {
            $this->line('Absents dans cette langue : '.implode(', ', $result['skipped']));
        }
        foreach ($result['failed'] as $key => $message) {
            $this->warn("{$key} : {$message}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function show(TemplateLibrary $library): int
    {
        $templates = config('whatsapp_templates.templates', []);

        foreach ($library->groups() as $group => $label) {
            $rows = [];
            foreach ($templates as $key => $template) {
                if ($template['group'] === $group) {
                    $rows[] = [$key, $template['title'], $template['category'], implode(', ', array_filter($library::LANGUAGES, fn ($l) => isset($template[$l])))];
                }
            }
            if ($rows) {
                $this->line("\n<fg=cyan>{$label}</>");
                $this->table(['Clé', 'Titre', 'Catégorie', 'Langues'], $rows);
            }
        }

        $this->line("\n<fg=cyan>Paquets</>");
        foreach ($library->packs() as $key => $pack) {
            $this->line(sprintf('  %-12s %d modèles : %s', $key, count($library->packKeys($key)), $pack['description']));
        }

        return self::SUCCESS;
    }
}
