<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_assistant_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('assistant_name', 80)->nullable();
            $table->text('personality')->nullable();
            $table->text('interaction_style')->nullable();
            $table->text('about_user')->nullable();
            $table->string('onboarding_status', 32);
            $table->string('onboarding_step', 32)->nullable();
            $table->foreignId('onboarding_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->timestamp('onboarding_started_at')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();

            $table->index('onboarding_status');
        });

        $now = now();

        DB::table('users')
            ->where('role', 'owner')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($owner) use ($now): void {
                DB::table('user_assistant_profiles')->insert([
                    'user_id' => $owner->id,
                    'assistant_name' => 'Jarvis',
                    'personality' => null,
                    'interaction_style' => null,
                    'about_user' => null,
                    'onboarding_status' => 'completed',
                    'onboarding_step' => null,
                    'onboarding_conversation_id' => null,
                    'onboarding_started_at' => null,
                    'onboarding_completed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_assistant_profiles');
    }
};
