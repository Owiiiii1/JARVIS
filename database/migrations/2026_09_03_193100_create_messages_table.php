<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('channel', 32);
            $table->text('body')->nullable();
            $table->string('message_type', 32);
            $table->string('channel_message_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('user_id');
            $table->index('occurred_at');
            $table->index('channel');
            $table->unique(
                ['channel', 'conversation_id', 'channel_message_id'],
                'messages_channel_conversation_external_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
