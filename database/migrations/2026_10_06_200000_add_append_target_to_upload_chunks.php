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
            $table->foreignId('append_to_chunk_id')->nullable()->constrained('upload_chunks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('upload_chunks', function (Blueprint $table): void {
            $table->dropForeign(['append_to_chunk_id']);
            $table->dropColumn('append_to_chunk_id');
        });
    }
};
