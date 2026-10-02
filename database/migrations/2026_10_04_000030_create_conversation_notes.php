<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supervision des conversations par le super administrateur : ses notes internes, ses signalements, ses « examinée » et les
 * résumés IA qu'il demande. Table volontairement séparée des conversations : un client ne lit jamais une note de l'équipe,
 * même si une vue affichait un jour toutes les colonnes d'une conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->index();
            $table->foreignId('user_id')->nullable()->index();
            // note : texte libre ; flag : à surveiller ; review : examinée ; summary : résumé fait par l'IA à la demande
            $table->string('kind', 10)->default('note');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'kind']);
        });

        // Le classement de la supervision parcourt toutes les entreprises par date d'activité.
        Schema::table('conversations', function (Blueprint $table) {
            $table->index('last_message_at', 'conversations_last_message_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_last_message_at_index');
        });
        Schema::dropIfExists('conversation_notes');
    }
};
