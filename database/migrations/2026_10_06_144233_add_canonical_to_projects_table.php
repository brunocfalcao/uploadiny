<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('canonical', 6)->nullable()->unique();
        });

        DB::table('projects')->select('id')->orderBy('id')->chunkById(100, function ($projects): void {
            foreach ($projects as $project) {
                do {
                    $canonical = strtolower(Str::password(6, numbers: false, symbols: false));
                } while (DB::table('projects')->where('canonical', $canonical)->exists());
                DB::table('projects')->where('id', $project->id)->update(['canonical' => $canonical]);
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['canonical']);
            $table->dropColumn('canonical');
        });
    }
};
