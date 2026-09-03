<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('external_user_id', 64);
            $table->string('external_chat_id', 64)->nullable();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamp('linked_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('active_conversation_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_user_id']);
            $table->index('user_id');
            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_identities');
    }
};
