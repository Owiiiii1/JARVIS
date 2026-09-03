<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->text('description')->nullable();
            $table->string('status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'normalized_name']);
            $table->index('user_id');
            $table->index('status');
        });

        Schema::create('project_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->timestamp('attached_at');
            $table->json('metadata')->nullable();

            $table->unique(['project_id', 'conversation_id']);
            $table->index('conversation_id');
        });

        Schema::create('project_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->timestamp('attached_at');
            $table->json('metadata')->nullable();

            $table->unique(['project_id', 'topic_id']);
            $table->index('topic_id');
        });

        Schema::create('project_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('memory_id')->constrained()->cascadeOnDelete();
            $table->timestamp('attached_at');
            $table->json('metadata')->nullable();

            $table->unique(['project_id', 'memory_id']);
            $table->index('memory_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_memories');
        Schema::dropIfExists('project_topics');
        Schema::dropIfExists('project_conversations');
        Schema::dropIfExists('projects');
    }
};
