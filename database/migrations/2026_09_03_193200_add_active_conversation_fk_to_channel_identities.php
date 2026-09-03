<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_identities', function (Blueprint $table) {
            $table->foreign('active_conversation_id')
                ->references('id')
                ->on('conversations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channel_identities', function (Blueprint $table) {
            $table->dropForeign(['active_conversation_id']);
        });
    }
};
