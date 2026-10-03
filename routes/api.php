<?php

declare(strict_types=1);
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ChunkController;
use App\Http\Controllers\ImageController;
use App\Http\Middleware\EnsureUploadinyDevice;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureUploadinyDevice::class)->group(function (): void {
    Route::get('/projects', [AgentController::class, 'projects'])->name('api.projects.index');
    Route::get('/projects/{project}/latest-chunk', [AgentController::class, 'latest'])->name('api.projects.latest');
    Route::post('/projects/{project}/chunks', [ChunkController::class, 'store'])->name('api.chunks.store');
    Route::get('/images/{image}/download', [ImageController::class, 'original'])->name('api.images.download');
    Route::get('/images/{image}/annotated', [ImageController::class, 'annotated'])->name('api.images.annotated');
    Route::post('/projects/{project}/chunks/start', [ChunkController::class, 'start'])->name('api.chunks.start');
    Route::post('/chunks/{chunk}/images', [ChunkController::class, 'append'])->name('api.chunks.append');
    Route::post('/chunks/{chunk}/complete', [ChunkController::class, 'complete'])->name('api.chunks.complete');
    Route::delete('/chunks/{chunk}', [ChunkController::class, 'cancel'])->name('api.chunks.cancel');
});
