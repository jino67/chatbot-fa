<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Lecture d'un site par tranches et catalogue de produits (photos comprises). Migration additive : aucune donnée existante
| n'est modifiée, les sources déjà lues gardent leurs extraits et se relisent à la demande.
*/
return new class extends Migration
{
    public function up(): void
    {
        // Les pages d'un site en cours de lecture : l'état de la lecture vit ici, pas dans la mémoire d'une requête.
        Schema::create('source_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 40);
            $table->string('status', 12)->default('pending'); // pending, done, skipped, failed
            $table->unsignedTinyInteger('depth')->default(0);
            $table->unsignedTinyInteger('priority')->default(4);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('title', 255)->nullable();
            $table->longText('content')->nullable();
            $table->char('content_hash', 40)->nullable();
            $table->json('products')->nullable();
            $table->string('note', 120)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'url_hash']);
            $table->index(['source_id', 'status', 'priority', 'depth', 'id']);
        });

        // Les produits connus d'un assistant : ce que l'assistant peut recommander et montrer en photo.
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->char('item_key', 40);
            $table->string('name', 160);
            $table->string('category', 80)->nullable();
            $table->string('price_text', 80)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('availability', 60)->nullable();
            $table->text('description')->nullable();
            $table->text('url')->nullable();
            $table->text('image_url')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->string('image_status', 12)->default('none'); // none, pending, ready, failed
            $table->timestamp('image_fetched_at')->nullable();
            $table->unsignedInteger('times_shown')->default(0);
            $table->timestamps();

            $table->unique(['bot_id', 'item_key']);
        });

        Schema::table('sources', function (Blueprint $table) {
            // Avancement de la lecture d'un site : pages trouvées, lues, limite de l'offre, phase.
            $table->json('progress')->nullable()->after('stats');
        });
    }

    public function down(): void
    {
        Schema::table('sources', fn (Blueprint $table) => $table->dropColumn('progress'));
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('source_pages');
    }
};
