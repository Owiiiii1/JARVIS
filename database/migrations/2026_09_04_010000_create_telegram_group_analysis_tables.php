<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_group_analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->string('analysis_type', 32);
            $table->timestamp('from_at');
            $table->timestamp('to_at');
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('provider', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('idempotency_key', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('telegram_group_id');
            $table->index('status');
            $table->index('idempotency_key');
            $table->index(['telegram_group_id', 'analysis_type', 'from_at', 'to_at'], 'tg_analysis_runs_group_range');
        });

        Schema::create('telegram_group_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('analysis_run_id')->nullable();
            $table->string('type', 32);
            $table->string('title')->nullable();
            $table->text('content');
            $table->json('structured_data')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('status', 32);
            $table->string('normalized_key')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->unsignedBigInteger('source_from_message_id')->nullable();
            $table->unsignedBigInteger('source_to_message_id')->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->string('generated_by_provider', 64)->nullable();
            $table->string('generated_by_model', 128)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['telegram_group_id', 'type', 'status'], 'tg_knowledge_group_type_status');
            $table->index(['telegram_group_id', 'type', 'normalized_key'], 'tg_knowledge_group_type_key');
            $table->index('status');
            $table->foreign('analysis_run_id')->references('id')->on('telegram_group_analysis_runs')->nullOnDelete();
            $table->foreign('source_from_message_id')->references('id')->on('messages')->nullOnDelete();
            $table->foreign('source_to_message_id')->references('id')->on('messages')->nullOnDelete();
            $table->foreign('supersedes_id')->references('id')->on('telegram_group_knowledge')->nullOnDelete();
        });

        Schema::create('telegram_group_knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_id')->constrained('telegram_group_knowledge')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['knowledge_id', 'message_id'], 'tg_knowledge_sources_unique');
            $table->index('message_id');
        });

        Schema::create('telegram_group_knowledge_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_id')->constrained('telegram_group_knowledge')->cascadeOnDelete();
            $table->text('previous_content')->nullable();
            $table->text('new_content');
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('knowledge_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_group_knowledge_revisions');
        Schema::dropIfExists('telegram_group_knowledge_sources');
        Schema::dropIfExists('telegram_group_knowledge');
        Schema::dropIfExists('telegram_group_analysis_runs');
    }
};
