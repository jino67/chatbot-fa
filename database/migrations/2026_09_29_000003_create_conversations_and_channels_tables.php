<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique(); // secret porte par le widget, jamais l'id numerique
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20)->default('web'); // web | whatsapp | playground
            $table->string('external_id')->nullable();     // id visiteur (web) ou numero (whatsapp)
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status', 20)->default('bot');  // bot | needs_human | human | closed
            $table->json('meta')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'channel', 'external_id']);
            $table->index(['workspace_id', 'status', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);                    // user | assistant | agent | system
            $table->text('content');
            $table->json('sources')->nullable();
            $table->json('meta')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->unique(['conversation_id', 'provider_message_id']);
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);                    // whatsapp_meta | whatsapp_twilio
            $table->string('status', 20)->default('pending'); // pending | active | disabled
            $table->string('display_phone')->nullable();
            $table->string('external_ref')->nullable();    // Meta : phone_number_id
            $table->text('credentials')->nullable();       // chiffre (cast encrypted:array)
            $table->json('config')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'external_ref']);
        });

        Schema::create('channel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('business_name');
            $table->string('phone_number');
            $table->string('country', 60)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('requested'); // requested | in_progress | active | rejected
            $table->text('admin_notes')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_requests');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
