<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_productivity_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('daily_brief_enabled')->default(false);
            $table->string('daily_brief_local_time', 5)->default('08:00');
            $table->boolean('evening_review_enabled')->default(false);
            $table->string('evening_review_local_time', 5)->default('20:00');
            $table->boolean('weekly_review_enabled')->default(false);
            $table->unsignedTinyInteger('weekly_review_weekday')->default(7);
            $table->string('weekly_review_local_time', 5)->default('18:00');
            $table->boolean('proactive_enabled')->default(false);
            $table->timestamp('last_daily_brief_at')->nullable();
            $table->timestamp('last_evening_review_at')->nullable();
            $table->timestamp('last_weekly_review_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_productivity_settings');
    }
};
