<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revision des offres apres les nouvelles fonctions : messages WhatsApp payes par la plateforme (volume inclus),
 * messages vocaux, import des discussions WhatsApp, offre API pour les developpeurs, et le dirham (DH).
 *
 * Prix recalcules d'apres les couts (voir docs/COUTS.md, section 6) : avec les messages WhatsApp a la charge de la
 * plateforme, l'ancienne grille (Pro 35 000, Business 90 000 FCFA) tombait a 23 % puis 2 % de marge si le client
 * consommait tout son volume via Twilio. Marges visees : au moins 65 % a usage normal, et au moins 25 % (Twilio) ou
 * 50 % (Meta direct) si tout le volume est consomme.
 */
return new class extends Migration
{
    private const GRID = [
        'free' => ['XOF' => 0, 'KMF' => 0, 'EUR' => 0, 'USD' => 0, 'MAD' => 0],
        'essentiel' => ['XOF' => 10000, 'KMF' => 7500, 'EUR' => 15, 'USD' => 17, 'MAD' => 165],
        'pro' => ['XOF' => 40000, 'KMF' => 30000, 'EUR' => 60, 'USD' => 70, 'MAD' => 660],
        'business' => ['XOF' => 120000, 'KMF' => 90000, 'EUR' => 180, 'USD' => 205, 'MAD' => 1975],
    ];

    /** Prix en FCFA de la premiere grille : une offre restee a ces valeurs recoit la nouvelle grille. */
    private const PREVIOUS_XOF = ['free' => 0, 'essentiel' => 10000, 'pro' => 35000, 'business' => 90000];

    /** Volumes inclus : messages WhatsApp par mois, evenements vocaux par mois. */
    private const ALLOWANCE = [
        'free' => [0, 0], 'essentiel' => [0, 0], 'pro' => [2500, 300], 'business' => [10000, 1500],
    ];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // « business » : page des tarifs ; « developer » : page Developpeurs (acces par API).
            $table->string('audience', 12)->default('business')->after('slug');
        });

        foreach (DB::table('plans')->get() as $plan) {
            $prices = json_decode((string) $plan->prices, true) ?: [];
            $limits = json_decode((string) $plan->limits, true) ?: [];
            $features = json_decode((string) $plan->features, true) ?: [];
            $slug = $plan->slug;

            $untouched = isset(self::PREVIOUS_XOF[$slug]) && ($prices['XOF'] ?? null) === self::PREVIOUS_XOF[$slug];
            if ($untouched) {
                $prices = self::GRID[$slug];
                [$whatsapp, $voice] = self::ALLOWANCE[$slug];
                $limits['whatsapp_messages_per_month'] = $whatsapp;
                $limits['voice_per_month'] = $voice;
            } elseif (! isset($prices['MAD']) && isset($prices['XOF'])) {
                // Offre personnalisee : le dirham est ajoute par conversion (10,8 DH pour un euro), arrondi a 5.
                $prices['MAD'] = (int) (round($prices['XOF'] / 655.957 * 10.8 / 5) * 5);
            }

            $limits['voice_per_month'] ??= 0;
            $features['voice'] = $features['voice'] ?? in_array($slug, ['pro', 'business'], true);
            $features['chat_import'] = $features['chat_import'] ?? in_array($slug, ['pro', 'business'], true);
            $features['api'] = $features['api'] ?? false;

            DB::table('plans')->where('id', $plan->id)->update([
                'prices' => json_encode($prices), 'limits' => json_encode($limits), 'features' => json_encode($features),
            ]);
        }

        // Offre pour les developpeurs : l'assistant en API, sans widget ni WhatsApp.
        if (! DB::table('plans')->where('slug', 'api')->exists()) {
            DB::table('plans')->insert([
                'slug' => 'api', 'audience' => 'developer', 'name' => 'API Développeur',
                'tagline' => 'Intégrez l\'assistant dans votre application',
                'prices' => json_encode(['XOF' => 20000, 'KMF' => 15000, 'EUR' => 30, 'USD' => 35, 'MAD' => 330]),
                'period_months' => 1, 'trial_days' => null,
                'limits' => json_encode(['bots' => 3, 'sources' => 40, 'pages_per_crawl' => 100, 'messages_per_month' => 3000, 'members' => 3, 'whatsapp_messages_per_month' => 0, 'voice_per_month' => 0]),
                'features' => json_encode(['whatsapp' => false, 'templates' => false, 'remove_branding' => true, 'priority_support' => false, 'voice' => false, 'chat_import' => false, 'api' => true]),
                'is_public' => true, 'is_default' => false, 'is_highlighted' => false, 'sort' => 20,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plans')->where('slug', 'api')->delete();

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
