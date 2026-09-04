<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_confirmations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('tool_name');
            $table->string('tool_call_id')->nullable();
            $table->longText('arguments_encrypted')->nullable();
            $table->string('status', 32);
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'conversation_id', 'status'], 'tool_confirmations_user_conversation_status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_confirmations');
    }
};
