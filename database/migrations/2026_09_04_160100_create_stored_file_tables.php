<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('original_name', 180)->nullable();
            $table->string('display_name', 180);
            $table->string('normalized_name', 180);
            $table->string('mime_type', 96);
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->nullable();
            $table->string('storage_disk', 32);
            $table->string('storage_path', 512);
            $table->string('status', 32);
            $table->unsignedInteger('extracted_chars')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->text('summary')->nullable();
            $table->string('client_upload_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'uploaded_at']);
            $table->index(['user_id', 'normalized_name']);
            $table->unique(['user_id', 'client_upload_id']);
        });

        Schema::create('stored_file_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stored_file_id')->constrained('stored_files')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->mediumText('content');
            $table->unsignedInteger('char_start')->nullable();
            $table->unsignedInteger('char_end')->nullable();
            $table->unsignedInteger('token_estimate')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['stored_file_id', 'chunk_index']);
        });

        Schema::create('message_stored_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stored_file_id')->constrained('stored_files')->cascadeOnDelete();
            $table->timestamp('attached_at');
            $table->timestamps();

            $table->unique(['message_id', 'stored_file_id']);
            $table->index(['stored_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_stored_files');
        Schema::dropIfExists('stored_file_chunks');
        Schema::dropIfExists('stored_files');
    }
};
