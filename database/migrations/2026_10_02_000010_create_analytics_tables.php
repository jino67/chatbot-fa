<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesure d'audience maison : une ligne par visite (analytics_sessions) et une par geste (analytics_events : pages vues,
 * clics, défilement, formulaires, erreurs, mesures de vitesse, actions dans l'application).
 *
 * Aucune adresse IP, aucun nom, aucun texte saisi : un identifiant aléatoire par navigateur et par visite. Le jour, l'heure
 * et le jour de la semaine sont gardés dans le fuseau de la plateforme au moment de l'écriture, pour que les cartes
 * d'affluence se calculent sans fonctions propres à un moteur de base de données.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_key', 32)->unique();
            $table->string('visitor_key', 32)->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('workspace_id')->nullable()->index();
            // visitor : personne non connectée ; client : compte d'une entreprise cliente ; staff : l'équipe (exclue par défaut).
            $table->string('audience', 10)->default('visitor');
            $table->timestamp('started_at')->index();
            $table->timestamp('last_seen_at');
            $table->date('date')->index();
            $table->unsignedTinyInteger('hour');
            $table->unsignedTinyInteger('dow');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedSmallInteger('pageviews')->default(0);
            $table->unsignedSmallInteger('events')->default(0);
            $table->boolean('is_bounce')->default(true);
            $table->boolean('is_new')->default(true);
            $table->boolean('is_pwa')->default(false);
            $table->string('entry_path')->nullable();
            $table->string('exit_path')->nullable();
            $table->string('source', 12)->default('direct');
            $table->string('referrer_host', 120)->nullable();
            $table->string('utm_source', 80)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 80)->nullable();
            $table->string('device', 10)->default('desktop');
            $table->string('browser', 30)->nullable();
            $table->string('os', 30)->nullable();
            $table->string('language', 10)->nullable();
            $table->string('timezone', 50)->nullable();
            $table->string('country', 2)->nullable();
            $table->timestamps();

            $table->index(['date', 'audience']);
        });

        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->nullable()->index();
            $table->string('visitor_key', 32)->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('workspace_id')->nullable()->index();
            $table->string('audience', 10)->default('visitor');
            // pageview, click, outbound, contact, scroll, engage, view, form_start, form_submit, rage, error, vital, action
            $table->string('type', 16);
            $table->string('name', 140)->nullable();
            $table->string('path')->nullable();
            $table->string('target')->nullable();
            $table->integer('value')->nullable();
            $table->boolean('cta')->default(false);
            $table->json('props')->nullable();
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->unsignedTinyInteger('dow');
            $table->timestamp('created_at')->index();

            $table->index(['date', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('analytics_sessions');
    }
};
