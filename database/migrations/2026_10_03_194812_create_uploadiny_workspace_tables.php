<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('upload_chunks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->timestamps();
        });
        Schema::create('upload_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('chunk_id')->constrained('upload_chunks')->restrictOnDelete();
            $table->string('name');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('feedback_revision')->default(0);
            $table->json('annotations')->nullable();
            $table->text('comments')->nullable();
            $table->string('annotated_path')->nullable();
            $table->text('description')->nullable();
            $table->string('description_status')->default('pending');
            $table->text('description_error')->nullable();
            $table->string('description_model')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'chunk_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_images');
        Schema::dropIfExists('upload_chunks');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('users');
    }
};
