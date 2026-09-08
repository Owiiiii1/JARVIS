<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voice_settings') || Schema::hasColumn('voice_settings', 'telegram_tts_speed')) {
            return;
        }

        Schema::table('voice_settings', function (Blueprint $table) {
            $table->decimal('telegram_tts_speed', 3, 2)->nullable()->after('elevenlabs_voice_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('voice_settings') || ! Schema::hasColumn('voice_settings', 'telegram_tts_speed')) {
            return;
        }

        Schema::table('voice_settings', function (Blueprint $table) {
            $table->dropColumn('telegram_tts_speed');
        });
    }
};
