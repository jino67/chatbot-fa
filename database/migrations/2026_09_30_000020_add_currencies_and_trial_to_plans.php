<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un prix par devise (FCFA, KMF, euro, dollar) au lieu d'un prix et d'une devise uniques, une durée d'essai
 * pour l'offre gratuite, et la devise choisie par chaque espace.
 *
 * Grille recalculée d'après les coûts réels (voir docs/COUTS.md) : le franc comorien et le FCFA sont
 * arrimés à l'euro (1 EUR = 491,968 KMF = 655,957 XOF), donc KMF = 75 % du prix en FCFA ; l'euro et le
 * dollar sont arrondis à un prix simple (dollar : 1 EUR = 1,1339 USD au 30 septembre 2026).
 */
return new class extends Migration
{
    private const GRID = [
        'free' => ['XOF' => 0, 'KMF' => 0, 'EUR' => 0, 'USD' => 0],
        'essentiel' => ['XOF' => 10000, 'KMF' => 7500, 'EUR' => 15, 'USD' => 17],
        'pro' => ['XOF' => 35000, 'KMF' => 26250, 'EUR' => 55, 'USD' => 60],
        'business' => ['XOF' => 90000, 'KMF' => 67500, 'EUR' => 135, 'USD' => 155],
    ];

    /** Prix de départ des anciennes graines : une offre restée à ces valeurs reçoit la nouvelle grille. */
    private const OLD_SEED_PRICE = ['free' => 0, 'essentiel' => 10000, 'pro' => 30000, 'business' => 75000];

    private const TRIAL_DAYS = 14;

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('prices')->nullable()->after('tagline');
            $table->unsignedSmallInteger('trial_days')->nullable()->after('period_months');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('currency', 3)->default('XOF')->after('plan');
        });

        foreach (DB::table('plans')->get() as $plan) {
            $untouched = (self::OLD_SEED_PRICE[$plan->slug] ?? null) === (int) $plan->price && $plan->currency === 'XOF';
            $update = ['prices' => json_encode($untouched ? self::GRID[$plan->slug] : [$plan->currency => (int) $plan->price])];

            if ($plan->slug === 'free' && $plan->is_default) {
                $update['trial_days'] = self::TRIAL_DAYS;
                if ($plan->tagline === 'Pour essayer sans risque') {
                    $update['tagline'] = self::TRIAL_DAYS.' jours pour essayer, sans carte bancaire';
                }
            }

            // L'offre à 10 000 FCFA n'inclut pas WhatsApp (coût de l'intégration) : site web seulement.
            if ($plan->slug === 'essentiel') {
                $features = json_decode($plan->features, true) ?: [];
                $features['whatsapp'] = false;
                $limits = json_decode($plan->limits, true) ?: [];
                if (($limits['messages_per_month'] ?? null) === 1500) {
                    $limits['messages_per_month'] = 1000;
                }
                $update['features'] = json_encode($features);
                $update['limits'] = json_encode($limits);
                if ($plan->tagline === 'Un assistant sur votre site et WhatsApp') {
                    $update['tagline'] = 'Votre assistant sur votre site web';
                }
            }

            DB::table('plans')->where('id', $plan->id)->update($update);
        }

        // Les espaces déjà sur l'offre gratuite sans date de fin démarrent un essai maintenant : rien ne s'interrompt d'un coup.
        $free = DB::table('plans')->where('is_default', true)->where('trial_days', '>', 0)->first();
        if ($free) {
            DB::table('workspaces')->where('plan', $free->slug)->whereNull('plan_ends_at')->update([
                'plan_ends_at' => now()->addDays((int) $free->trial_days),
                'subscription_status' => 'trialing',
            ]);
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('price')->default(0)->after('tagline');
            $table->string('currency', 3)->default('XOF')->after('price');
        });

        foreach (DB::table('plans')->get() as $plan) {
            $prices = json_decode((string) $plan->prices, true) ?: [];
            $code = array_key_exists('XOF', $prices) ? 'XOF' : (array_key_first($prices) ?? 'XOF');
            DB::table('plans')->where('id', $plan->id)->update(['price' => (int) ($prices[$code] ?? 0), 'currency' => $code]);
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['prices', 'trial_days']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
