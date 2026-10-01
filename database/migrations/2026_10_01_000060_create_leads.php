<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes à traiter : une commande à confirmer, un rendez-vous, un devis, ou un client qui demande une personne.
 * Elles servent d'« étiquettes » internes (l'API WhatsApp Cloud ne permet pas d'étiqueter une conversation) et de base
 * aux alertes du propriétaire (e-mail, WhatsApp, tableau de bord) et à leurs rappels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);                       // order | appointment | quote | human
            $table->string('status', 12)->default('new');     // new | taken | done | dismissed
            $table->string('title', 160);
            $table->text('summary')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('alerted_at')->nullable();
            $table->json('alert_log')->nullable();
            $table->unsignedTinyInteger('reminders')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['conversation_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
