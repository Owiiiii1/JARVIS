<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->text('summary');
            $table->unsignedBigInteger('from_message_id')->nullable();
            $table->unsignedBigInteger('to_message_id')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 32);
            $table->string('generated_by_provider', 64)->nullable();
            $table->string('generated_by_model', 128)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('conversation_id');
            $table->index('status');
            $table->index('generated_at');
            $table->index(['user_id', 'conversation_id', 'status', 'version'], 'conversation_summaries_user_chat_status_version');
            $table->foreign('from_message_id')->references('id')->on('messages')->nullOnDelete();
            $table->foreign('to_message_id')->references('id')->on('messages')->nullOnDelete();
        });

        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->text('description')->nullable();
            $table->string('status', 32);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'normalized_name']);
            $table->index('user_id');
            $table->index('status');
            $table->index('normalized_name');
        });

        Schema::create('message_topic_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('source', 32);
            $table->timestamps();

            $table->unique(['message_id', 'topic_id']);
            $table->index('topic_id');
        });

        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 32);
            $table->string('kind', 32);
            $table->text('content');
            $table->string('normalized_key')->nullable();
            $table->decimal('confidence', 5, 4);
            $table->string('status', 32);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_confirmed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'kind', 'normalized_key'], 'memories_user_kind_key');
            $table->index(['user_id', 'status', 'confidence'], 'memories_user_status_confidence');
            $table->index('valid_until');
        });

        Schema::create('memory_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('summary_id')->nullable();
            $table->string('source_kind', 32);
            $table->timestamp('created_at')->useCurrent();

            $table->index('memory_id');
            $table->index('message_id');
            $table->index('conversation_id');
            $table->foreign('summary_id')->references('id')->on('conversation_summaries')->nullOnDelete();
        });

        Schema::create('memory_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('memory_id')->constrained()->cascadeOnDelete();
            $table->text('previous_content')->nullable();
            $table->text('new_content');
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('memory_id');
            $table->foreign('source_message_id')->references('id')->on('messages')->nullOnDelete();
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('updated_from_memory_at')->nullable();
            $table->timestamps();
        });

        Schema::create('memory_analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('from_message_id')->nullable();
            $table->unsignedBigInteger('to_message_id')->nullable();
            $table->string('type', 32);
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['conversation_id', 'type', 'from_message_id', 'to_message_id'],
                'memory_analysis_runs_idempotent'
            );
            $table->index('user_id');
            $table->index('status');
            $table->foreign('from_message_id')->references('id')->on('messages')->nullOnDelete();
            $table->foreign('to_message_id')->references('id')->on('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_analysis_runs');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('memory_revisions');
        Schema::dropIfExists('memory_sources');
        Schema::dropIfExists('memories');
        Schema::dropIfExists('message_topic_relations');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('conversation_summaries');
    }
};
