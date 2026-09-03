<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bot_settings', function (Blueprint $table) {
            $table->id();
            $table->text('bot_token')->nullable();
            $table->string('bot_username')->nullable();
            $table->string('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('is_webhook_set')->default(false);
            $table->boolean('is_connected')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_webhook_set_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_settings');
    }
};
