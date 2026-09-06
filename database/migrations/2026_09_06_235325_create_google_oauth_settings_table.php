<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_oauth_settings', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->string('redirect_uri', 2048)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_oauth_settings');
    }
};
