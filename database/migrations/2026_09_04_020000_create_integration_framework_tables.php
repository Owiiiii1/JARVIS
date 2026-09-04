<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_account_id')->default('');
            $table->string('external_account_email')->nullable();
            $table->string('status', 32);
            $table->json('scopes')->nullable();
            $table->longText('credentials_encrypted')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'external_account_id'], 'integration_accounts_user_provider_ext_unique');
            $table->index(['user_id', 'provider', 'status']);
        });

        Schema::create('tool_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool_name');
            $table->string('capability')->nullable();
            $table->string('provider', 32)->nullable();
            $table->foreignId('integration_account_id')->nullable()->constrained('integration_accounts')->nullOnDelete();
            $table->string('status', 32);
            $table->string('confirmation_state', 32)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('tool_name');
            $table->index('provider');
            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_execution_logs');
        Schema::dropIfExists('integration_accounts');
    }
};
