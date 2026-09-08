<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 180);
            $table->string('status', 32)->default('active');
            $table->string('timezone', 64)->default('UTC');
            $table->string('schedule_kind', 32)->default('daily_local');
            $table->string('local_time', 5);
            $table->json('days')->nullable();
            $table->string('report_type', 48);
            $table->string('period_mode', 48);
            $table->json('sources');
            $table->json('delivery');
            $table->json('cursor')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('created_by', 32)->default('tool');
            $table->timestamps();

            $table->index(['status', 'next_run_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('scheduled_report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_report_id')->constrained('scheduled_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slot_key', 64);
            $table->string('status', 32)->default('running');
            $table->json('collected')->nullable();
            $table->text('body')->nullable();
            $table->unsignedBigInteger('notification_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->json('source_errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['scheduled_report_id', 'slot_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_report_runs');
        Schema::dropIfExists('scheduled_reports');
    }
};
