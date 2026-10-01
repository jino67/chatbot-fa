<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi de consommation en temps reel : chaque reponse de l'IA, chaque message WhatsApp entrant, sortant ou en
 * modele, chaque message vocal, avec son cout estime. Les messages WhatsApp sont payes par la plateforme (compte
 * Twilio et compte Meta centralises), pas par les clients : c'est ce journal qui permet de voir, client par
 * client, ce que chacun consomme. Un credit de messages permet aux clients sans carte bancaire de recharger
 * par Mobile Money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);                       // ai_answer | wa_in | wa_out | wa_template | voice_in | voice_out
            $table->string('provider', 20)->nullable();       // anthropic, openai, meta, twilio...
            $table->string('detail', 60)->nullable();         // modele IA, categorie de modele WhatsApp...
            $table->unsignedInteger('units')->default(1);     // 1 message, ou des secondes de voix
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['kind', 'created_at']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            // Messages WhatsApp achetes en plus de ceux de l'offre (recharge payee par Mobile Money).
            $table->unsignedInteger('wa_credit')->default(0)->after('currency');
        });

        // Chaque offre payante avec WhatsApp inclut un volume mensuel de messages, paye par la plateforme.
        $allowance = ['free' => 0, 'essentiel' => 0, 'pro' => 3000, 'business' => 12000];
        foreach (DB::table('plans')->get() as $plan) {
            $limits = json_decode($plan->limits, true) ?: [];
            $limits['whatsapp_messages_per_month'] ??= $allowance[$plan->slug] ?? 0;
            DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
        }
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('wa_credit');
        });

        Schema::dropIfExists('usage_events');
    }
};
