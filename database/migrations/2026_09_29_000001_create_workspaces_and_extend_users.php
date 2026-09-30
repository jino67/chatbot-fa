<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Offre courante (slug d'une ligne de `plans`) et cycle d'abonnement.
            $table->string('plan', 30)->default('free');
            $table->string('subscription_status', 20)->default('active'); // active | trialing | past_due | canceled
            $table->timestamp('plan_started_at')->nullable();
            $table->timestamp('plan_ends_at')->nullable();
            $table->boolean('is_suspended')->default(false);
            $table->string('suspended_reason')->nullable();
            $table->string('country', 60)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('notes')->nullable();     // notes internes de l'equipe, jamais visibles du client
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            // super_admin : tout ; admin : gere les espaces clients ; client : son propre espace.
            $table->string('role', 20)->default('client');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn(['role', 'is_active', 'last_login_at']);
        });
        Schema::dropIfExists('workspaces');
    }
};
