<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reminder_id')->constrained()->cascadeOnDelete();
            $table->timestamp('run_at');
            $table->string('status', 32);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('delivery_snapshot')->nullable();
            $table->timestamps();

            $table->index(['reminder_id', 'run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_occurrences');
    }
};
