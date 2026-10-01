<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications sur les téléphones et dans l'application :
 *  - push_subscriptions : un appareil (navigateur ou application installée) qui a accepté de recevoir des notifications ;
 *  - app_notifications  : le centre de notifications d'une personne (cloche, compteur sur l'icône), une ligne par message ;
 *  - push_campaigns     : les envois de l'équipe (promotions, nouveautés, messages importants), avec leur audience et leurs résultats ;
 *  - users.notification_prefs : ce que la personne accepte de recevoir (par catégorie) et ses heures calmes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->foreignId('workspace_id')->nullable()->index();
            // L'adresse d'un abonnement est longue : on l'identifie par son empreinte pour l'unicité.
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('p256dh', 120);
            $table->string('auth', 40);
            $table->string('platform', 12)->default('other');
            $table->string('user_agent', 255)->nullable();
            $table->boolean('standalone')->default(false);
            $table->unsignedTinyInteger('failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
        });

        Schema::create('push_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->index();
            $table->string('title', 80);
            $table->string('body', 240);
            $table->string('url', 255)->nullable();
            // promo : nouveautés et offres (la personne peut les refuser, un seul par jour, pas la nuit) ; important : message de service.
            $table->string('kind', 12)->default('promo');
            $table->json('audience');
            $table->boolean('also_email')->default(false);
            $table->string('status', 12)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // L'envoi se fait par morceaux (hébergement mutualisé) : on retient jusqu'où on est allé.
            $table->unsignedBigInteger('cursor_user_id')->default(0);
            $table->unsignedInteger('targeted')->default(0);
            $table->unsignedInteger('notified')->default(0);
            $table->unsignedInteger('pushed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('deferred')->default(0);
            $table->unsignedInteger('emailed')->default(0);
            $table->timestamps();
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->foreignId('workspace_id')->nullable()->index();
            // leads, handoffs, account, system, promo
            $table->string('category', 16)->index();
            $table->string('title', 120);
            $table->string('body', 300)->nullable();
            $table->string('url', 255)->nullable();
            $table->foreignId('campaign_id')->nullable()->index();
            $table->string('tag', 60)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            // Envoi différé (heures calmes) : le planificateur pousse la notification à partir de cette heure.
            $table->timestamp('push_after')->nullable()->index();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['user_id', 'read_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_prefs');
        });
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('push_campaigns');
        Schema::dropIfExists('push_subscriptions');
    }
};
