<?php

use Illuminate\Support\Facades\Route;
use TimMcLeod\AgentWorkflowsUi\Http\Controllers\DashboardController;

Route::get('/', [DashboardController::class, 'index'])->name('index');
Route::get('/data', [DashboardController::class, 'indexData'])->name('data');

Route::get('/runs/{run}', [DashboardController::class, 'show'])->name('show');
Route::get('/runs/{run}/data', [DashboardController::class, 'showData'])->name('show.data');
