<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staged_file_deletions', function (Blueprint $table): void {
            $table->id();
            $table->json('paths');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staged_file_deletions');
    }
};
