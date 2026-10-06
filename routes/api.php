<?php

declare(strict_types=1);

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ChunkController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\ImageController;
use App\UploadinyTokenAbility;
use Illuminate\Support\Facades\Route;

Route::post('/device-tokens', [DeviceTokenController::class, 'store'])->middleware('throttle:device-token')->name('api.device-tokens.store');

Route::middleware(['auth:sanctum', 'abilities:'.UploadinyTokenAbility::PROJECTS_READ])->group(function (): void {
    Route::get('/projects', [AgentController::class, 'projects'])->name('api.projects.index');
});

Route::middleware(['auth:sanctum', 'abilities:'.implode(',', UploadinyTokenAbility::agent())])->group(function (): void {
    Route::get('/feedback/{project:canonical}', [AgentController::class, 'latest'])->where('project', '[a-z]{6}')->name('api.feedback.latest');
    Route::get('/projects/{project}/latest-chunk', [AgentController::class, 'latest'])->name('api.projects.latest');
    Route::get('/images/{image}/download', [ImageController::class, 'original'])->name('api.images.download');
    Route::get('/images/{image}/annotated', [ImageController::class, 'annotated'])->name('api.images.annotated');
});

Route::middleware(['auth:sanctum', 'abilities:'.UploadinyTokenAbility::UPLOADS_WRITE])->group(function (): void {
    Route::post('/projects/{project}/chunks', [ChunkController::class, 'store'])->name('api.chunks.store');
    Route::get('/projects/{project}/last-chunk', [ChunkController::class, 'last'])->name('api.chunks.last');
    Route::post('/projects/{project}/chunks/start', [ChunkController::class, 'start'])->name('api.chunks.start');
    Route::post('/chunks/{chunk}/images', [ChunkController::class, 'append'])->name('api.chunks.append');
    Route::post('/chunks/{chunk}/complete', [ChunkController::class, 'complete'])->name('api.chunks.complete');
    Route::delete('/chunks/{chunk}', [ChunkController::class, 'cancel'])->name('api.chunks.cancel');
});
