<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voice_settings') || Schema::hasColumn('voice_settings', 'stt_model')) {
            return;
        }

        Schema::table('voice_settings', function (Blueprint $table) {
            $table->string('stt_model', 64)->nullable()->after('stt_provider');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('voice_settings') || ! Schema::hasColumn('voice_settings', 'stt_model')) {
            return;
        }

        Schema::table('voice_settings', function (Blueprint $table) {
            $table->dropColumn('stt_model');
        });
    }
};
