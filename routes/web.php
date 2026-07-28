<?php

use Illuminate\Support\Facades\Route;
use TimMcLeod\AgentWorkflowsUi\Http\Controllers\DashboardController;

Route::get('/', [DashboardController::class, 'index'])->name('index');
Route::get('/data', [DashboardController::class, 'indexData'])->name('data');

Route::get('/runs/{run}', [DashboardController::class, 'show'])->name('show');
Route::get('/runs/{run}/data', [DashboardController::class, 'showData'])->name('show.data');
Route::post('/runs/{run}/resume', [DashboardController::class, 'resume'])->name('resume');
Route::post('/runs/{run}/retry', [DashboardController::class, 'retry'])->name('retry');
Route::post('/runs/{run}/cancel', [DashboardController::class, 'cancel'])->name('cancel');
