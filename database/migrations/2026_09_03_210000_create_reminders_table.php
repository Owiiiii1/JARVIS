<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('text');
            $table->timestamp('run_at');
            $table->string('timezone', 64);
            $table->string('original_local_time')->nullable();
            $table->string('status', 32);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('recurrence_rule')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('run_at');
            $table->index(['status', 'run_at']);
        });

        $this->unlockReminderLanguageInConversationPrompts();
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }

    private function unlockReminderLanguageInConversationPrompts(): void
    {
        $owner = DB::table('ai_role_settings')->where('role_key', 'owner_conversation')->value('system_prompt');

        if (is_string($owner) && str_contains($owner, 'Do not invent tools, integrations, or access to other users.')) {
            DB::table('ai_role_settings')
                ->where('role_key', 'owner_conversation')
                ->update([
                    'system_prompt' => str_replace(
                        'Do not invent tools, integrations, or access to other users.',
                        'Use only tools provided in this turn. Do not invent tools, integrations, or access to other users.',
                        $owner
                    ),
                    'updated_at' => now(),
                ]);
        }

        $user = DB::table('ai_role_settings')->where('role_key', 'user_conversation')->value('system_prompt');

        if (is_string($user) && str_contains($user, 'You currently cannot use tools or access other users\' data.')) {
            DB::table('ai_role_settings')
                ->where('role_key', 'user_conversation')
                ->update([
                    'system_prompt' => str_replace(
                        'You currently cannot use tools or access other users\' data.',
                        'You may use tools provided in this turn. Do not invent tools. Do not access other users\' data.',
                        $user
                    ),
                    'updated_at' => now(),
                ]);
        }
    }
};
