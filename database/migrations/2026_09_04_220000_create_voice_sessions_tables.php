<?php

use App\Enums\VoiceSttProvider;
use App\Enums\VoiceTtsProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_settings', function (Blueprint $table) {
            $table->id();
            $table->string('stt_provider', 32);
            $table->string('tts_provider', 32);
            $table->boolean('spoken_style_enabled')->default(true);
            $table->text('elevenlabs_api_key')->nullable();
            $table->string('elevenlabs_voice_id', 64)->nullable();
            $table->timestamps();
        });

        DB::table('voice_settings')->insert([
            'stt_provider' => VoiceSttProvider::normalize(config('voice.stt_provider'))->value,
            'tts_provider' => VoiceTtsProvider::normalize(config('voice.tts_provider'))->value,
            'spoken_style_enabled' => (bool) config('voice.spoken_style.enabled', true),
            'elevenlabs_api_key' => null,
            'elevenlabs_voice_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('voice_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 16);
            $table->string('status', 24);
            $table->string('stt_provider', 32)->nullable();
            $table->string('tts_provider', 32)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('conversation_id');
            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_sessions');
        Schema::dropIfExists('voice_settings');
    }
};
