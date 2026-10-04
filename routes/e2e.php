<?php

declare(strict_types=1);

use App\Http\Controllers\E2e\E2eHelperController;
use Illuminate\Support\Facades\Route;

Route::prefix('__e2e__')->group(function (): void {
    Route::get('/health', [E2eHelperController::class, 'health'])->name('e2e.health');
    Route::post('/scenarios/{scenario}', [E2eHelperController::class, 'createScenario'])->name('e2e.scenarios.create');
    Route::post('/ai-scenario', [E2eHelperController::class, 'setAiScenario'])->name('e2e.ai-scenario');
    Route::get('/intakes/{intakeId}/uploads', [E2eHelperController::class, 'uploads'])->name('e2e.uploads');
});
