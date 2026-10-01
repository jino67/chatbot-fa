<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cles d'API de l'offre developpeurs. Une cle est liee a un assistant ; seule son empreinte (SHA-256) est stockee :
 * la cle en clair n'est montree qu'une fois, a sa creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('prefix', 12);                 // debut de la cle, pour la reconnaitre dans la liste
            $table->string('key_hash', 64)->unique();     // sha256 de la cle complete
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
