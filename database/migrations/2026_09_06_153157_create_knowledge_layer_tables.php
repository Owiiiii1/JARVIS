<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('name', 180);
            $table->string('normalized_name', 180);
            $table->text('summary')->nullable();
            $table->string('status', 32)->default('active');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('confidence', 5, 4)->default(0.95);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'normalized_name']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('knowledge_entity_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_entity_id')->constrained('knowledge_entities')->cascadeOnDelete();
            $table->string('alias', 180);
            $table->string('normalized_alias', 180);
            $table->string('source_type', 32);
            $table->decimal('confidence', 5, 4)->default(0.95);
            $table->timestamps();

            $table->unique(['knowledge_entity_id', 'normalized_alias'], 'ke_aliases_entity_norm_unique');
            $table->index(['user_id', 'normalized_alias'], 'ke_aliases_user_norm_idx');
        });

        Schema::create('knowledge_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_entity_id');
            $table->unsignedBigInteger('target_entity_id');
            $table->string('type', 48);
            $table->string('label', 180)->nullable();
            $table->string('status', 32)->default('active');
            $table->decimal('confidence', 5, 4)->default(0.95);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('source_count')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('source_entity_id', 'krel_source_fk')->references('id')->on('knowledge_entities')->cascadeOnDelete();
            $table->foreign('target_entity_id', 'krel_target_fk')->references('id')->on('knowledge_entities')->cascadeOnDelete();
            $table->unique(['user_id', 'source_entity_id', 'target_entity_id', 'type'], 'krel_user_pair_type_unique');
            $table->index(['user_id', 'type', 'status'], 'krel_user_type_status_idx');
            $table->index(['source_entity_id', 'type'], 'krel_source_type_idx');
            $table->index(['target_entity_id', 'type'], 'krel_target_type_idx');
        });

        Schema::create('knowledge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48);
            $table->string('title', 240);
            $table->timestamp('occurred_at');
            $table->string('source_type', 32);
            $table->string('source_fingerprint', 64);
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('confidence', 5, 4)->default(0.95);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source_fingerprint'], 'kevents_user_fp_unique');
            $table->index(['user_id', 'occurred_at'], 'kevents_user_occurred_idx');
            $table->index(['user_id', 'type'], 'kevents_user_type_idx');
        });

        Schema::create('knowledge_event_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_event_id')->constrained('knowledge_events')->cascadeOnDelete();
            $table->foreignId('knowledge_entity_id')->constrained('knowledge_entities')->cascadeOnDelete();
            $table->string('role', 32)->default('subject');
            $table->timestamps();

            $table->unique(['knowledge_event_id', 'knowledge_entity_id'], 'kevent_entities_unique');
            $table->index(['knowledge_entity_id', 'knowledge_event_id'], 'kevent_entities_entity_idx');
        });

        Schema::create('knowledge_entity_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_entity_id')->constrained('knowledge_entities')->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->string('source_fingerprint', 64);
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('memory_id')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('reminder_id')->nullable();
            $table->unsignedBigInteger('stored_file_id')->nullable();
            $table->decimal('confidence', 5, 4)->default(0.95);
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_entity_id', 'source_fingerprint'], 'kesources_entity_fp_unique');
            $table->index(['user_id', 'source_fingerprint'], 'kesources_user_fp_idx');
            $table->index('conversation_id', 'kesources_conversation_idx');
            $table->foreign('memory_id', 'kesources_memory_fk')->references('id')->on('memories')->nullOnDelete();
            $table->foreign('task_id', 'kesources_task_fk')->references('id')->on('tasks')->nullOnDelete();
            $table->foreign('reminder_id', 'kesources_reminder_fk')->references('id')->on('reminders')->nullOnDelete();
            $table->foreign('stored_file_id', 'kesources_file_fk')->references('id')->on('stored_files')->nullOnDelete();
        });

        Schema::create('knowledge_analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->string('source_fingerprint', 64);
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source_fingerprint'], 'kruns_user_fp_unique');
            $table->index(['user_id', 'status'], 'kruns_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_analysis_runs');
        Schema::dropIfExists('knowledge_entity_sources');
        Schema::dropIfExists('knowledge_event_entities');
        Schema::dropIfExists('knowledge_events');
        Schema::dropIfExists('knowledge_relationships');
        Schema::dropIfExists('knowledge_entity_aliases');
        Schema::dropIfExists('knowledge_entities');
    }
};
