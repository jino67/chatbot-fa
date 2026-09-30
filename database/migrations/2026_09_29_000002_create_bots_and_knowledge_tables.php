<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('public_key', 40)->unique();
            $table->string('sector', 30)->nullable();        // secteur d'activite (voir config/sectors.php)
            $table->json('profile')->nullable();             // profil de l'entreprise et personnalite de l'assistant
            $table->text('instructions')->nullable();       // consigne active, modifiable par le client
            $table->text('instructions_default')->nullable(); // derniere consigne generee, pour la restaurer
            $table->string('language', 8)->default('fr');
            $table->text('welcome_message')->nullable();
            $table->text('fallback_message')->nullable();
            $table->json('suggested_questions')->nullable();
            $table->json('theme')->nullable();
            $table->json('allowed_origins')->nullable();
            $table->string('handoff_email')->nullable();
            $table->boolean('collect_contact')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);            // file | image | url | text | qa | facebook
            $table->string('name');
            $table->string('status', 20)->default('pending'); // pending | processing | ready | failed
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->json('stats')->nullable();
            $table->string('resync', 10)->default('never');   // never | daily | weekly
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'status']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('url', 2048)->nullable();
            $table->longText('content');
            $table->string('content_hash', 64);
            $table->timestamps();
        });

        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('heading_path', 500)->nullable();
            $table->text('content');
            $table->unsignedInteger('token_count')->default(0);
            // Vecteur float32 packe puis encode en base64 (portable). Migration pgvector : voir docs/ARCHITECTURE.md.
            $table->text('embedding')->nullable();
            $table->string('embedding_model', 80)->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'embedding_model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('bots');
    }
};
