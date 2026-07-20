<?php

declare(strict_types=1);

use App\Http\Controllers\Customer\IntakeUploadController as CustomerIntakeUploadController;
use App\Http\Controllers\Demo\StartDemoController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Installer\AddressSuggestionController;
use App\Http\Controllers\Installer\DashboardController;
use App\Http\Controllers\Installer\IntakeController;
use App\Http\Controllers\Installer\IntakeUploadController as InstallerIntakeUploadController;
use App\Http\Controllers\Installer\MetricsController;
use App\Http\Controllers\ProfileController;
use App\Livewire\Customer\IntakeWizard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', HealthController::class)->name('health');

Route::post('/demo/start', StartDemoController::class)
    ->middleware('throttle:demo-start')
    ->name('demo.start');

Route::middleware(['customer.intake', 'throttle:customer-intake'])
    ->where(['token' => '[A-Za-z0-9]{64}'])
    ->group(function () {
        Route::get('/o/{token}', IntakeWizard::class)->name('customer.intake.show');
        Route::get('/o/{token}/uploads/{upload}', [CustomerIntakeUploadController::class, 'show'])
            ->name('customer.uploads.show');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/metrics', MetricsController::class)->name('metrics');

    Route::get('/address-suggestions', AddressSuggestionController::class)
        ->middleware('throttle:60,1')
        ->name('address-suggestions');

    Route::get('/intakes/create', [IntakeController::class, 'create'])->name('intakes.create');
    Route::post('/intakes', [IntakeController::class, 'store'])->name('intakes.store');
    Route::get('/intakes/{intake}', [IntakeController::class, 'show'])->name('intakes.show');
    Route::post('/intakes/{intake}/review', [IntakeController::class, 'review'])->name('intakes.review');
    Route::post('/intakes/{intake}/attention/suggest', [IntakeController::class, 'suggestAttention'])->name('intakes.attention.suggest');
    Route::post('/intakes/{intake}/attention/{point}/accept', [IntakeController::class, 'acceptAttention'])->name('intakes.attention.accept');
    Route::post('/intakes/{intake}/attention/{point}/dismiss', [IntakeController::class, 'dismissAttention'])->name('intakes.attention.dismiss');
    Route::post('/intakes/{intake}/revoke', [IntakeController::class, 'revoke'])->name('intakes.revoke');
    Route::post('/intakes/{intake}/regenerate-token', [IntakeController::class, 'regenerateToken'])->name('intakes.regenerate-token');
    Route::post('/intakes/{intake}/send-link', [IntakeController::class, 'sendLink'])->name('intakes.send-link');
    Route::get('/intakes/{intake}/rapport', [IntakeController::class, 'previewReport'])->name('intakes.report');
    Route::get('/intakes/{intake}/rapport.pdf', [IntakeController::class, 'downloadPdf'])->name('intakes.pdf');
    Route::post('/intakes/{intake}/pdf', [IntakeController::class, 'regeneratePdf'])->name('intakes.pdf.regenerate');
    Route::get('/intakes/{intake}/uploads/{upload}', [InstallerIntakeUploadController::class, 'show'])
        ->name('installer.uploads.show');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
