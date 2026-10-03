<?php

declare(strict_types=1);
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChunkController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});
Route::middleware('auth')->group(function (): void {
    Route::get('/', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}/latest-chunk', [AgentController::class, 'latest'])->name('projects.latest');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/chunks', [ChunkController::class, 'store'])->name('chunks.store');
    Route::get('/images/{image}', [ImageController::class, 'show'])->name('images.show');
    Route::get('/images/{image}/preview', [ImageController::class, 'original'])->name('images.preview');
    Route::get('/images/{image}/download', [ImageController::class, 'original'])->name('images.download');
    Route::get('/images/{image}/annotated', [ImageController::class, 'annotated'])->name('images.annotated');
    Route::patch('/images/{image}', [ImageController::class, 'update'])->name('images.update');
    Route::patch('/images/{image}/project', [ImageController::class, 'move'])->name('images.move');
    Route::delete('/images/{image}', [ImageController::class, 'destroy'])->name('images.destroy');
    Route::post('/images/{image}/description', [ImageController::class, 'describe'])->name('images.describe');
    Route::post('/projects/{project}/chunks/start', [ChunkController::class, 'start'])->name('chunks.start');
    Route::post('/chunks/{chunk}/images', [ChunkController::class, 'append'])->name('chunks.append');
    Route::post('/chunks/{chunk}/complete', [ChunkController::class, 'complete'])->name('chunks.complete');
    Route::delete('/chunks/{chunk}', [ChunkController::class, 'cancel'])->name('chunks.cancel');
});
