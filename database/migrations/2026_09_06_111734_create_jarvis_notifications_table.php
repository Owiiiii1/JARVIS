<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jarvis_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('title');
            $table->text('body');
            $table->string('severity', 16)->default('info');
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('dedupe_key', 191);
            $table->string('action_url', 255)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('occurred_at');
            $table->boolean('ai_phrased')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jarvis_notifications');
    }
};
