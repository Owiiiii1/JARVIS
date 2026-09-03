<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_groups', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_chat_id', 64)->unique();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('username')->nullable();
            $table->string('chat_type', 32);
            $table->string('status', 32);
            $table->string('timezone', 64)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->json('settings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('last_message_at');
        });

        Schema::create('telegram_group_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->string('telegram_user_id', 64);
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->boolean('is_bot')->default(false);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['telegram_group_id', 'telegram_user_id'], 'telegram_group_participants_group_user_unique');
        });

        Schema::create('project_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->timestamp('attached_at');
            $table->json('metadata')->nullable();

            $table->unique(['project_id', 'telegram_group_id']);
            $table->index('telegram_group_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('telegram_group_id')->nullable()->after('conversation_id')->constrained('telegram_groups')->nullOnDelete();
            $table->string('sender_external_id', 64)->nullable()->after('channel_message_id');
            $table->string('sender_username', 64)->nullable()->after('sender_external_id');
            $table->string('sender_name')->nullable()->after('sender_username');
            $table->string('reply_to_channel_message_id', 64)->nullable()->after('parent_message_id');
            $table->string('thread_id', 64)->nullable()->after('reply_to_channel_message_id');
            $table->timestamp('edited_at')->nullable()->after('occurred_at');

            $table->index('telegram_group_id');
            $table->index('thread_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['telegram_group_id']);
            $table->dropIndex(['telegram_group_id']);
            $table->dropIndex(['thread_id']);
            $table->dropColumn([
                'telegram_group_id',
                'sender_external_id',
                'sender_username',
                'sender_name',
                'reply_to_channel_message_id',
                'thread_id',
                'edited_at',
            ]);
        });

        Schema::dropIfExists('project_groups');
        Schema::dropIfExists('telegram_group_participants');
        Schema::dropIfExists('telegram_groups');
    }
};
