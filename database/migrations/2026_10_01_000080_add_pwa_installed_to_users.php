<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date à laquelle la personne a ouvert le site en mode application (icône sur l'écran d'accueil). Sur iPhone, le
 * navigateur ne peut pas savoir que l'icône existe : c'est l'application elle-même qui le signale au serveur, et
 * l'invitation à l'installer ne revient plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('pwa_installed_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pwa_installed_at');
        });
    }
};
