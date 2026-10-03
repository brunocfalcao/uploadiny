<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_chunks', function (Blueprint $table): void {
            $table->foreignId('upload_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('status')->default('complete');
            $table->unsignedInteger('expected_images')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('upload_chunks', function (Blueprint $table): void {
            $table->dropForeign(['upload_project_id']);
            $table->dropColumn(['upload_project_id', 'status', 'expected_images', 'completed_at']);
        });
    }
};
