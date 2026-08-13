<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/detect', [DashboardController::class, 'detect'])->name('dashboard.detect');

Route::get('/scans', [ScanController::class, 'index'])->name('scans.index');
Route::post('/scans', [ScanController::class, 'store'])->name('scans.store');
Route::get('/scans/{scan}', [ScanController::class, 'show'])->name('scans.show');
