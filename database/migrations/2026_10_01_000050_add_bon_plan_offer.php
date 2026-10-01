<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Offre « Bon plan » à 25 000 FCFA : un seul assistant, site web et WhatsApp, entre Essentiel (site seulement) et Pro
 * (plusieurs assistants, sans mention « Propulsé par », modèles WhatsApp).
 *
 * Volumes calculés d'après les coûts (docs/COUTS.md, section 6) pour 25 000 FCFA, soit 43,2 USD :
 *   - fixe : hébergement 5 USD + frais de paiement 3 % (1,30 USD) = 6,30 USD ;
 *   - 2 000 réponses IA au pire tarif (Haiku 4.5, marge de sécurité comprise) : 8,80 USD ;
 *   - 800 messages WhatsApp par Twilio (0,005 USD chacun) : 4,00 USD, ou 0 par Meta direct ;
 *   - 100 événements vocaux, au pire 0,01 USD chacun : 1,00 USD.
 * Usage normal (40 % du volume) : 11,50 USD de coûts, soit 73 % de marge. Volume entier : 20,10 USD par Twilio
 * (marge 53 %) et 16,10 USD par Meta direct (marge 63 %).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('plans')->where('slug', 'bonplan')->exists()) {
            return;
        }

        // Le Bon plan s'intercale entre Essentiel et Pro.
        DB::table('plans')->whereIn('slug', ['pro', 'business'])->where('audience', 'business')->increment('sort');

        DB::table('plans')->insert([
            'slug' => 'bonplan', 'audience' => 'business', 'name' => 'Bon plan',
            'tagline' => 'Un assistant complet, sur votre site et WhatsApp, au juste prix',
            'prices' => json_encode(['XOF' => 25000, 'KMF' => 18750, 'EUR' => 38, 'USD' => 43, 'MAD' => 410]),
            'period_months' => 1, 'trial_days' => null,
            'limits' => json_encode([
                'bots' => 1, 'sources' => 40, 'pages_per_crawl' => 100, 'messages_per_month' => 2000, 'members' => 3,
                'whatsapp_messages_per_month' => 800, 'voice_per_month' => 100,
            ]),
            'features' => json_encode([
                'whatsapp' => true, 'templates' => false, 'remove_branding' => false, 'priority_support' => false,
                'voice' => true, 'chat_import' => false, 'api' => false,
            ]),
            'is_public' => true, 'is_default' => false, 'is_highlighted' => false, 'sort' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('plans')->where('slug', 'bonplan')->delete();
        DB::table('plans')->whereIn('slug', ['pro', 'business'])->where('audience', 'business')->decrement('sort');
    }
};
