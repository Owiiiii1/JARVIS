<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('name', 180);
            $table->string('status', 32)->default('active');
            $table->string('health', 32)->default('healthy');
            $table->string('mode', 32)->default('recurring');
            $table->string('trigger_type', 48);
            $table->string('source_type', 48);
            $table->json('source_config');
            $table->string('condition_type', 48);
            $table->json('condition_config');
            $table->string('reaction_type', 48);
            $table->json('reaction_config');
            $table->unsignedInteger('cooldown_seconds')->default(3600);
            $table->unsignedInteger('max_triggers_per_day')->default(8);
            $table->unsignedInteger('aggregation_window_seconds')->default(300);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->json('cursor')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->string('last_error_category', 48)->nullable();
            $table->string('last_error', 240)->nullable();
            $table->timestamp('blocked_notified_at')->nullable();
            $table->string('created_by', 32)->default('tool');
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('knowledge_entity_id')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('reminder_id')->nullable();
            $table->unsignedBigInteger('integration_account_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'next_check_at']);
            $table->index(['user_id', 'trigger_type']);
            $table->foreign('knowledge_entity_id', 'watchers_entity_fk')->references('id')->on('knowledge_entities')->nullOnDelete();
            $table->foreign('task_id', 'watchers_task_fk')->references('id')->on('tasks')->nullOnDelete();
            $table->foreign('reminder_id', 'watchers_reminder_fk')->references('id')->on('reminders')->nullOnDelete();
            $table->foreign('integration_account_id', 'watchers_account_fk')->references('id')->on('integration_accounts')->nullOnDelete();
        });

        Schema::create('watcher_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watcher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('trigger_fingerprint', 64);
            $table->timestamp('detected_at');
            $table->string('status', 32);
            $table->string('matched_condition', 64)->nullable();
            $table->string('reaction_status', 32)->default('pending');
            $table->unsignedBigInteger('notification_id')->nullable();
            $table->unsignedBigInteger('knowledge_event_id')->nullable();
            $table->string('error_category', 48)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['watcher_id', 'trigger_fingerprint'], 'wocc_watcher_fp_unique');
            $table->index(['user_id', 'detected_at'], 'wocc_user_detected_idx');
            $table->index(['watcher_id', 'detected_at'], 'wocc_watcher_detected_idx');
            $table->foreign('notification_id', 'wocc_notification_fk')->references('id')->on('jarvis_notifications')->nullOnDelete();
            $table->foreign('knowledge_event_id', 'wocc_knowledge_event_fk')->references('id')->on('knowledge_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watcher_occurrences');
        Schema::dropIfExists('watchers');
    }
};
