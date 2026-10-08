<?php

use App\Services\WorkspaceDeletion;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(WorkspaceDeletion::class)->cleanupPending())->name('uploadiny-staged-file-cleanup')->everyMinute()->withoutOverlapping();
