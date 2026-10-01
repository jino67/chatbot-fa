<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Langues parlees par l'assistant (plusieurs, modifiables a tout moment) et reglages de la voix : comprendre les
 * messages vocaux, repondre en audio (jamais, comme le client, toujours), style de voix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->json('languages')->nullable()->after('language');
            $table->boolean('voice_in')->default(true)->after('languages');
            $table->string('voice_out', 8)->default('mirror')->after('voice_in');   // never | mirror | always
            $table->string('voice_style', 12)->default('feminine')->after('voice_out'); // feminine | masculine | neutral
        });

        // Les assistants existants gardent leurs langues : celles choisies a la creation, sinon la langue principale.
        foreach (DB::table('bots')->get() as $bot) {
            $profile = json_decode((string) $bot->profile, true) ?: [];
            $languages = array_values(array_unique(array_merge([$bot->language], (array) ($profile['languages'] ?? []))));
            DB::table('bots')->where('id', $bot->id)->update(['languages' => json_encode($languages)]);
        }
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn(['languages', 'voice_in', 'voice_out', 'voice_style']);
        });
    }
};
