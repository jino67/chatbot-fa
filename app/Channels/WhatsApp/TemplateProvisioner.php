<?php

namespace App\Channels\WhatsApp;

use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;

/**
 * Crée d'un coup plusieurs modèles de la bibliothèque sur un canal WhatsApp (Meta ou Twilio) et les soumet à
 * approbation. Rejouable : un modèle qui existe déjà (même nom, même langue) n'est jamais recréé ; l'échec d'un
 * modèle n'arrête pas les suivants, sauf si le fournisseur refuse tout (identifiants, droits).
 */
class TemplateProvisioner
{
    /** Après autant d'échecs de suite, on s'arrête : le fournisseur refuse tout, inutile d'insister. */
    private const MAX_FAILURES_IN_A_ROW = 3;

    public function __construct(
        private readonly TemplateLibrary $library,
        private readonly TemplateManager $manager,
    ) {}

    /**
     * @param  list<string>  $keys
     * @return array{created:list<string>, existing:list<string>, skipped:list<string>, failed:array<string,string>}
     */
    public function provision(Channel $channel, Bot $bot, array $keys, ?string $language = null): array
    {
        $language ??= $this->library->languageFor($bot->language);
        $context = $this->library->context($bot, $channel);
        $existing = array_keys($this->library->existing($channel, $language));

        $result = ['created' => [], 'existing' => [], 'skipped' => [], 'failed' => []];
        $inARow = 0;

        foreach (array_values(array_unique($keys)) as $key) {
            if (! $this->library->has($key, $language)) {
                $result['skipped'][] = $key;

                continue;
            }
            if (in_array($key, $existing, true)) {
                $result['existing'][] = $key;

                continue;
            }

            try {
                $this->manager->create($channel, $this->library->definition($key, $language, $context));
                $result['created'][] = $key;
                $inARow = 0;
            } catch (GatewayException $e) {
                $result['failed'][$key] = $e->getMessage();

                if (++$inARow >= self::MAX_FAILURES_IN_A_ROW) {
                    break;
                }
            }

            // Les fournisseurs limitent le rythme de création : une courte pause entre deux modèles.
            if (($pause = (int) config('platform.whatsapp.template_pause_ms', 250)) > 0) {
                usleep($pause * 1000);
            }
        }

        if ($result['created'] !== []) {
            AuditLog::record('template.library_created', $bot->name, ['bot' => $bot->id, 'count' => count($result['created']), 'language' => $language], $channel->workspace_id);
        }

        return $result;
    }

    /**
     * À l'activation d'un canal : le paquet de base et celui du métier de l'assistant, sans rien exiger du client.
     * Rien si l'offre n'inclut pas les modèles ou si la création automatique est coupée.
     *
     * @return ?array{created:list<string>, existing:list<string>, skipped:list<string>, failed:array<string,string>}
     */
    public function autoProvision(Channel $channel): ?array
    {
        if (! config('platform.whatsapp.auto_templates', true)) {
            return null;
        }

        $workspace = Workspace::withoutGlobalScopes()->find($channel->workspace_id);
        $bot = Bot::withoutGlobalScopes()->find($channel->bot_id);

        if (! $workspace || ! $bot || ! $workspace->hasFeature('templates')) {
            return null;
        }

        return $this->provision($channel, $bot, $this->library->packKeys($this->library->packFor($bot->sector)), $this->library->languageFor($bot->language));
    }
}
