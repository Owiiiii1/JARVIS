<?php

use App\Enums\AiRoleKey;
use App\Services\Ai\DefaultRolePrompts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_role_settings', function (Blueprint $table) {
            $table->id();
            $table->string('role_key', 64)->unique();
            $table->string('provider', 32)->nullable();
            $table->string('model', 255)->nullable();
            $table->text('system_prompt');
            $table->json('parameters')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });

        $now = now();

        foreach (AiRoleKey::cases() as $role) {
            DB::table('ai_role_settings')->insert([
                'role_key' => $role->value,
                'provider' => null,
                'model' => null,
                'system_prompt' => DefaultRolePrompts::for($role),
                'parameters' => json_encode(['recent_message_limit' => 30], JSON_THROW_ON_ERROR),
                'is_enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_role_settings');
    }
};
