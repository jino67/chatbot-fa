<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des personnes inscrites par l'équipe de la plateforme : qui a été contacté, par quel moyen, avec quel résultat, et
 * quand relancer. Uniquement des ajouts : aucune donnée existante n'est touchée.
 *
 * - customer_contacts : le journal des contacts (e-mail, WhatsApp, appel, notification, note, rendez-vous).
 * - users.crm_* : où en est la relation (statut), la prochaine relance, qui s'en occupe, le dernier contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->foreignId('staff_id')->nullable()->index();
            // email, whatsapp, call, push, note, meeting
            $table->string('channel', 12);
            $table->string('subject', 190)->nullable();
            $table->text('body')->nullable();
            // replied, interested, no_answer, not_interested, converted
            $table->string('outcome', 16)->nullable();
            $table->string('template', 30)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('crm_status', 16)->nullable()->index();
            $table->timestamp('crm_next_follow_up_at')->nullable()->index();
            $table->unsignedBigInteger('crm_owner_id')->nullable()->index();
            $table->timestamp('crm_last_contacted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['crm_status']);
            $table->dropIndex(['crm_next_follow_up_at']);
            $table->dropIndex(['crm_owner_id']);
            $table->dropColumn(['crm_status', 'crm_next_follow_up_at', 'crm_owner_id', 'crm_last_contacted_at']);
        });
        Schema::dropIfExists('customer_contacts');
    }
};
