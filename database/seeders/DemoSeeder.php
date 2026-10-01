<?php

namespace Database\Seeders;

use App\Chat\InstructionGenerator;
use App\Ingestion\IngestionPipeline;
use App\Models\Bot;
use App\Models\Plan;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PlatformSettings;
use Illuminate\Database\Seeder;

/**
 * Donnees de demonstration : uniquement pour un environnement local.
 * Les entreprises, prix et numeros ci-dessous sont FICTIFS.
 *
 * Comptes crees (mot de passe de developpement : voir DEV_PASSWORD) :
 *  - admin@kouma.test  : super admin (PlatformSeeder)
 *  - equipe@kouma.test : admin (gere les espaces clients)
 *  - awa@kouma.test    : cliente de demonstration (boutique fictive)
 *
 * L'assistant « Kouma » de la vitrine repond, sur la page d'accueil, aux questions sur le produit lui-meme.
 */
class DemoSeeder extends Seeder
{
    private const DEV_PASSWORD = 'kouma-demo-2026';

    public function run(IngestionPipeline $pipeline, InstructionGenerator $generator, PlatformSettings $settings): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder refuse de tourner en production.');

            return;
        }

        $this->call(PlatformSeeder::class);

        // En local, les comptes de demonstration partagent un mot de passe connu.
        $super = User::where('email', 'admin@kouma.test')->first();
        $super?->forceFill(['password' => self::DEV_PASSWORD])->save();

        $staff = User::firstOrNew(['email' => 'equipe@kouma.test']);
        $staff->forceFill(['name' => 'Équipe Kouma', 'password' => self::DEV_PASSWORD, 'workspace_id' => null]);
        $staff->role = User::ADMIN;
        $staff->save();

        $awa = $this->boutiqueAwa($pipeline, $generator);
        app(\App\Services\LandingBot::class)->sync();

        $settings->set('brand.email', $settings->get('brand.email') ?: (config('brand.email') ?: 'contact@kouma.test'));

        $this->command?->info('Démo prête. Comptes (mot de passe : '.self::DEV_PASSWORD.') : admin@kouma.test (super admin), equipe@kouma.test (admin), awa@kouma.test (client).');
        $this->command?->info("Boutique Awa : /demo/{$awa->public_key}");
    }

    private function boutiqueAwa(IngestionPipeline $pipeline, InstructionGenerator $generator): Bot
    {
        $workspace = Workspace::firstOrCreate(['slug' => 'boutique-awa'], ['name' => 'Boutique Awa (démo)', 'plan' => 'pro', 'plan_started_at' => now()]);

        $owner = User::firstOrNew(['email' => 'awa@kouma.test']);
        $owner->forceFill(['name' => 'Awa Ouedraogo', 'password' => self::DEV_PASSWORD, 'workspace_id' => $workspace->id])->save();

        $profile = array_replace($generator->defaultProfile(), [
            'description' => 'Boutique de prêt-à-porter et d\'accessoires en wax, faits par des couturiers de Ouagadougou.',
            'city' => 'Ouagadougou', 'country' => 'Burkina Faso',
            'hours' => 'Du lundi au samedi de 8 h à 19 h, le dimanche de 9 h à 13 h.',
            'phone' => '+226 70 00 00 00',
            'offers' => 'Robes en wax, boubous brodés, sacs en cuir, foulards. Retouches gratuites pour toute robe.',
            'extra_rules' => "Ne propose jamais de remise.\nPour une commande, demande le nom du client et son quartier.",
        ]);

        $bot = Bot::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('name', 'Assistante Boutique Awa')->first();
        if (! $bot) {
            $instructions = $generator->generate('Assistante Boutique Awa', 'Boutique Awa', 'commerce', $profile);
            $bot = Bot::create([
                'workspace_id' => $workspace->id,
                'name' => 'Assistante Boutique Awa',
                'language' => 'fr',
                'sector' => 'commerce',
                'profile' => $profile,
                'instructions' => $instructions,
                'instructions_default' => $instructions,
                'welcome_message' => "Bonjour ! Je suis l'assistante de la Boutique Awa. Prix, livraison, horaires : posez-moi votre question.",
                'suggested_questions' => ['Vous livrez à Bobo-Dioulasso ?', 'Quels sont vos horaires ?', 'Comment payer ?'],
                'theme' => ['color' => '#0f766e', 'position' => 'right', 'title' => 'Boutique Awa'],
                'allowed_origins' => [],
                'handoff_email' => 'awa@kouma.test',
            ]);
        }

        $this->ingest($pipeline, $bot, [
            'Livraison' => "# Livraison\nNous livrons à Ouagadougou et à Bobo-Dioulasso.\n\n## Tarifs et délais\nOuagadougou : 1 000 FCFA, livré sous 24 h. Bobo-Dioulasso : 2 000 FCFA, livré sous 48 h. La livraison est gratuite dès 25 000 FCFA d'achat.\n\n## Autres villes\nExpédition par bus, frais selon la destination, à confirmer avec l'équipe.",
            'Paiement' => "# Paiement\nPaiement à la livraison en espèces, par Orange Money ou par Moov Money. Le paiement par carte bancaire n'est pas disponible pour le moment.\n\nPour une commande sur mesure, un acompte de 50 % est demandé à la commande.",
            'Horaires et adresse' => "# Horaires et adresse\nLa boutique est ouverte du lundi au samedi de 8 h à 19 h, et le dimanche de 9 h à 13 h.\n\nAdresse : Avenue Kwame N'Krumah, Ouagadougou, à côté de la pharmacie. Téléphone et WhatsApp : +226 70 00 00 00.",
            'Retours et échanges' => "# Retours et échanges\nUn échange est possible sous 7 jours avec le ticket de caisse, si l'article n'a pas été porté et se trouve dans son emballage. Aucun remboursement : seul un avoir est remis. Les articles soldés ne sont ni repris ni échangés.",
            'Catalogue et prix' => "# Catalogue et prix\nRobe en wax, modèle Fasso : 18 000 FCFA.\nBoubou brodé pour homme : 35 000 FCFA.\nSac en cuir artisanal : 22 000 FCFA.\nFoulard en soie : 6 500 FCFA.\n\nRetouches gratuites pour tout achat de robe.",
        ]);

        return $bot;
    }

    /** @param array<string,string> $knowledge */
    private function ingest(IngestionPipeline $pipeline, Bot $bot, array $knowledge): void
    {
        foreach ($knowledge as $title => $content) {
            $source = Source::withoutGlobalScopes()->firstOrCreate(
                ['bot_id' => $bot->id, 'type' => Source::TYPE_TEXT, 'name' => $title],
                ['workspace_id' => $bot->workspace_id, 'payload' => ['content' => $content]]
            );

            if ($source->status !== Source::READY) {
                $pipeline->run($source);
            }
        }
    }
}
