<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32);
            $table->string('priority', 32)->default('normal');
            $table->timestamp('due_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->foreignId('source_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('calendar_provider', 32)->nullable();
            $table->string('calendar_id', 191)->nullable();
            $table->string('calendar_event_id', 191)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'due_at']);
            $table->index('parent_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
