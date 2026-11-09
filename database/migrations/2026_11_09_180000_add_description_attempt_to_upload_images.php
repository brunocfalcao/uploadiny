<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_images', function (Blueprint $table): void {
            $table->uuid('description_attempt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('upload_images', function (Blueprint $table): void {
            $table->dropColumn('description_attempt');
        });
    }
};
