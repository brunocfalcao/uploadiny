<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('staged_file_deletions', function (Blueprint $table): void {
            $table->string('journal_path')->nullable()->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staged_file_deletions', function (Blueprint $table): void {
            $table->dropUnique(['journal_path']);
            $table->dropColumn(['journal_path', 'attempts', 'last_attempt_at', 'last_error']);
        });
    }
};
