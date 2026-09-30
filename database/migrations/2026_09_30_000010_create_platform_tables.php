<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Offres d'abonnement (editables par le super admin) ----------------------------
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();
            $table->string('name');
            $table->string('tagline')->nullable();
            $table->unsignedInteger('price')->default(0);       // par periode, sans decimales (FCFA)
            $table->string('currency', 3)->default('XOF');
            $table->unsignedSmallInteger('period_months')->default(1);
            $table->json('limits');                              // bots, sources, pages_per_crawl, messages_per_month, members
            $table->json('features');                            // whatsapp, templates, remove_branding, priority_support
            $table->boolean('is_public')->default(true);         // visible sur la page des tarifs
            $table->boolean('is_default')->default(false);       // offre attribuee a l'inscription
            $table->boolean('is_highlighted')->default(false);   // mise en avant sur la page des tarifs
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        $limits = fn (int $bots, int $sources, int $pages, int $messages, int $members) => json_encode([
            'bots' => $bots, 'sources' => $sources, 'pages_per_crawl' => $pages, 'messages_per_month' => $messages, 'members' => $members,
        ]);
        $features = fn (bool $whatsapp, bool $templates, bool $noBranding, bool $support) => json_encode([
            'whatsapp' => $whatsapp, 'templates' => $templates, 'remove_branding' => $noBranding, 'priority_support' => $support,
        ]);

        // Offres provisoires : les prix se reglent depuis le tableau de bord (voir docs/COUTS.md).
        DB::table('plans')->insert([
            ['slug' => 'free', 'name' => 'Découverte', 'tagline' => 'Pour essayer sans risque', 'price' => 0, 'currency' => 'XOF', 'period_months' => 1,
                'limits' => $limits(1, 4, 10, 100, 1), 'features' => $features(false, false, false, false),
                'is_public' => true, 'is_default' => true, 'is_highlighted' => false, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'essentiel', 'name' => 'Essentiel', 'tagline' => 'Un assistant sur votre site et WhatsApp', 'price' => 10000, 'currency' => 'XOF', 'period_months' => 1,
                'limits' => $limits(1, 20, 50, 1500, 2), 'features' => $features(true, false, false, false),
                'is_public' => true, 'is_default' => false, 'is_highlighted' => false, 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'pro', 'name' => 'Pro', 'tagline' => 'Pour une activité qui reçoit beaucoup de messages', 'price' => 30000, 'currency' => 'XOF', 'period_months' => 1,
                'limits' => $limits(3, 60, 200, 5000, 5), 'features' => $features(true, true, true, false),
                'is_public' => true, 'is_default' => false, 'is_highlighted' => true, 'sort' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'business', 'name' => 'Business', 'tagline' => 'Plusieurs assistants, une équipe, un suivi dédié', 'price' => 75000, 'currency' => 'XOF', 'period_months' => 1,
                'limits' => $limits(10, 300, 500, 15000, 15), 'features' => $features(true, true, true, true),
                'is_public' => true, 'is_default' => false, 'is_highlighted' => false, 'sort' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // ---- Paiements enregistres (Mobile Money, virement, especes) et demandes d'abonnement -
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 30);
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('XOF');
            $table->string('method', 30);                       // orange_money | moov_money | coris_money | wave | virement | especes | autre
            $table->string('reference')->nullable();            // numero de transaction
            $table->unsignedSmallInteger('period_months')->default(1);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'paid_at']);
        });

        Schema::create('plan_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan', 30);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('requested'); // requested | approved | rejected
            $table->text('admin_notes')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // ---- Parametres de la plateforme (marque, contact, embeddings, IA...) --------------
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();                   // JSON ; chiffre si is_secret
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });

        // ---- Fournisseurs de modeles de langage et bascule ---------------------------------
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver', 30);                        // anthropic | openai_compatible
            $table->string('preset', 30)->nullable();            // anthropic | openai | openrouter | deepinfra | together | groq | ollama | custom
            $table->text('api_key')->nullable();                 // chiffre ; vide = cle de l'environnement
            $table->string('base_url')->nullable();
            $table->string('model');
            $table->string('vision_model')->nullable();
            $table->boolean('supports_vision')->default(false);
            $table->decimal('price_in', 8, 4)->nullable();       // USD par million de jetons, pour les estimations de cout
            $table->decimal('price_out', 8, 4)->nullable();
            $table->unsignedSmallInteger('priority')->default(10); // ordre de la chaine de bascule (petit = d'abord)
            $table->boolean('enabled')->default(true);
            $table->string('status', 20)->default('unknown');    // ok | cooling | down | unknown
            $table->timestamp('disabled_until')->nullable();     // disjoncteur : ne pas essayer avant cette date
            $table->string('last_error_kind', 30)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedBigInteger('requests_count')->default(0);
            $table->unsignedBigInteger('failures_count')->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->timestamps();
        });

        $providers = [
            ['name' => 'Anthropic (Claude Haiku)', 'driver' => 'anthropic', 'preset' => 'anthropic', 'model' => 'claude-haiku-4-5', 'vision_model' => null,
                'supports_vision' => true, 'price_in' => 1.0, 'price_out' => 5.0, 'priority' => 1],
            ['name' => 'OpenAI (GPT mini)', 'driver' => 'openai_compatible', 'preset' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini',
                'supports_vision' => true, 'price_in' => 0.15, 'price_out' => 0.6, 'priority' => 2],
            ['name' => 'Llama 3.3 70B (OpenRouter)', 'driver' => 'openai_compatible', 'preset' => 'openrouter', 'base_url' => 'https://openrouter.ai/api/v1',
                'model' => 'meta-llama/llama-3.3-70b-instruct', 'supports_vision' => false, 'price_in' => 0.10, 'price_out' => 0.32, 'priority' => 3],
        ];
        foreach ($providers as $provider) {
            DB::table('ai_providers')->insert($provider + ['enabled' => true, 'status' => 'unknown', 'created_at' => $now, 'updated_at' => $now]);
        }

        // ---- Modeles de messages WhatsApp -------------------------------------------------
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();            // id Meta ou ContentSid Twilio
            $table->string('name');
            $table->string('language', 10)->default('fr');
            $table->string('category', 20)->default('UTILITY');   // UTILITY | MARKETING | AUTHENTICATION
            $table->string('status', 20)->default('DRAFT');       // DRAFT | PENDING | APPROVED | REJECTED | PAUSED | DISABLED
            $table->json('components')->nullable();               // structure Meta (HEADER, BODY, FOOTER, BUTTONS)
            $table->text('body')->nullable();                     // texte du corps, pour l'affichage et le remplissage
            $table->unsignedTinyInteger('variables_count')->default(0);
            $table->text('rejected_reason')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'name', 'language']);
        });

        // ---- Connexions Facebook (API officielle Graph) -----------------------------------
        Schema::create('facebook_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('page_id');
            $table->string('page_name')->nullable();
            $table->string('page_url')->nullable();
            $table->text('access_token')->nullable();             // jeton de page, chiffre
            $table->string('status', 20)->default('active');      // active | expired
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['bot_id', 'page_id']);
        });

        // ---- Journal d'activite : qui a fait quoi, surtout cote equipe ---------------------
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at']);
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'facebook_connections', 'whatsapp_templates', 'ai_providers', 'platform_settings', 'plan_requests', 'payments', 'plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
