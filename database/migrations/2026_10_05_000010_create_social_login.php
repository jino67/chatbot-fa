<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connexion avec Google, Apple, Microsoft ou Facebook. Uniquement des ajouts : aucune donnée existante n'est touchée.
 *
 * - social_accounts : un compte externe relié à un utilisateur (identifiant chez le fournisseur, e-mail, nom, photo).
 *   Aucun jeton n'est gardé : on s'en sert une fois, à la connexion.
 * - users.has_password : faux pour une personne arrivée par un fournisseur et qui n'a pas encore choisi de mot de passe.
 * - users.needs_profile : vrai tant qu'une personne arrivée par un fournisseur n'a pas donné son entreprise et son numéro.
 * - users.signup_source : comment la personne s'est inscrite (vide pour les comptes d'avant : on lit « e-mail »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_user_id', 191);
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index(['user_id', 'provider']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('has_password')->default(true);
            $table->boolean('needs_profile')->default(false);
            $table->string('signup_source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['has_password', 'needs_profile', 'signup_source']);
        });
        Schema::dropIfExists('social_accounts');
    }
};
