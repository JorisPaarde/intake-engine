<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Installer\DashboardController;
use App\Http\Controllers\Installer\IntakeController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', HealthController::class)->name('health');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/intakes/create', [IntakeController::class, 'create'])->name('intakes.create');
    Route::post('/intakes', [IntakeController::class, 'store'])->name('intakes.store');
    Route::get('/intakes/{intake}', [IntakeController::class, 'show'])->name('intakes.show');
    Route::post('/intakes/{intake}/revoke', [IntakeController::class, 'revoke'])->name('intakes.revoke');
    Route::post('/intakes/{intake}/regenerate-token', [IntakeController::class, 'regenerateToken'])->name('intakes.regenerate-token');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
