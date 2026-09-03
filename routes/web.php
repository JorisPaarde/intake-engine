<?php

declare(strict_types=1);

use App\Domains\Intake\Services\PublicDemoSession;
use App\Http\Controllers\CompanyLogoController;
use App\Http\Controllers\Customer\IntakeUploadController as CustomerIntakeUploadController;
use App\Http\Controllers\Demo\ChooseDemoPathController;
use App\Http\Controllers\Demo\LoadDemoScenarioController;
use App\Http\Controllers\Demo\RequestDemoReportPdfController;
use App\Http\Controllers\Demo\StartDemoController;
use App\Http\Controllers\Dev\DevActivityController;
use App\Http\Controllers\Dev\DevAiRunController;
use App\Http\Controllers\Dev\DevDashboardController;
use App\Http\Controllers\Dev\DevHealthController;
use App\Http\Controllers\Dev\DevIntakeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Installer\AddressSuggestionController;
use App\Http\Controllers\Installer\CompanySettingsController;
use App\Http\Controllers\Installer\DashboardController;
use App\Http\Controllers\Installer\IntakeController;
use App\Http\Controllers\Installer\IntakeUploadController as InstallerIntakeUploadController;
use App\Http\Controllers\Installer\MetricsController;
use App\Http\Controllers\Installer\SurveyWorkspaceController;
use App\Http\Controllers\ProductInterestController;
use App\Http\Controllers\ProfileController;
use App\Livewire\Customer\IntakeWizard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request, PublicDemoSession $publicDemoSession) {
    return view('welcome', [
        'isPublicDemo' => $publicDemoSession->isActive($request),
    ]);
})->name('home');

Route::post('/interesse', ProductInterestController::class)
    ->middleware('throttle:product-interest')
    ->name('product-interest.store');

Route::get('/health', HealthController::class)->name('health');

Route::post('/demo/start', StartDemoController::class)
    ->middleware(['guest', 'throttle:demo-start'])
    ->name('demo.start');

Route::get('/demo/beeindigd', function (Request $request) {
    $reason = $request->query('reason', 'ended');

    return view('demo.ended', [
        'reason' => in_array($reason, ['ended', 'expired'], true) ? $reason : 'ended',
    ]);
})->name('demo.ended');

Route::middleware(['customer.intake', 'throttle:customer-intake'])
    ->where(['token' => '[A-Za-z0-9]{64}'])
    ->group(function () {
        Route::get('/o/{token}', IntakeWizard::class)->name('customer.intake.show');
        Route::get('/o/{token}/company-logo', [CompanyLogoController::class, 'customer'])
            ->name('customer.company-logo.show');
        Route::get('/o/{token}/uploads/{upload}', [CustomerIntakeUploadController::class, 'show'])
            ->name('customer.uploads.show');
    });

Route::middleware(['auth', 'verified', 'public.demo.scope'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/metrics', MetricsController::class)->name('metrics');
    Route::get('/settings/company', [CompanySettingsController::class, 'edit'])->name('company.settings.edit');
    Route::patch('/settings/company', [CompanySettingsController::class, 'update'])->name('company.settings.update');
    Route::get('/companies/{company}/logo', [CompanyLogoController::class, 'installer'])->name('company.logo.show');

    Route::get('/address-suggestions', AddressSuggestionController::class)
        ->middleware('throttle:60,1')
        ->name('address-suggestions');

    Route::get('/intakes/create', [IntakeController::class, 'create'])->name('intakes.create');
    Route::post('/intakes', [IntakeController::class, 'store'])->name('intakes.store');
    Route::post('/intakes/{intake}/demo/path', ChooseDemoPathController::class)->name('demo.path.choose');
    Route::post('/intakes/{intake}/demo/scenario', LoadDemoScenarioController::class)->name('demo.scenario.load');
    Route::post('/intakes/{intake}/demo/rapport-pdf', RequestDemoReportPdfController::class)
        ->middleware('throttle:product-interest')
        ->name('demo.report-pdf');
    Route::get('/intakes/{intake}', [IntakeController::class, 'show'])->name('intakes.show');
    Route::post('/intakes/{intake}/address-enrichment', [IntakeController::class, 'retryAddressEnrichment'])
        ->middleware('throttle:10,1')
        ->name('intakes.address-enrichment.retry');
    Route::get('/intakes/{intake}/opname', [SurveyWorkspaceController::class, 'show'])->name('intakes.workspace');
    Route::post('/intakes/{intake}/opname/rooms', [SurveyWorkspaceController::class, 'storeRoom'])->name('intakes.workspace.rooms.store');
    Route::post('/intakes/{intake}/opname/rooms/{room}', [SurveyWorkspaceController::class, 'updateRoom'])->name('intakes.workspace.rooms.update');
    Route::post('/intakes/{intake}/opname/placements', [SurveyWorkspaceController::class, 'storePlacement'])->name('intakes.workspace.placements.store');
    Route::post('/intakes/{intake}/opname/placements/{placement}', [SurveyWorkspaceController::class, 'updatePlacement'])->name('intakes.workspace.placements.update');
    Route::post('/intakes/{intake}/opname/options', [SurveyWorkspaceController::class, 'storeInstallationOption'])->name('intakes.workspace.options.store');
    Route::post('/intakes/{intake}/opname/options/{option}/select', [SurveyWorkspaceController::class, 'selectInstallationOption'])->name('intakes.workspace.options.select');
    Route::post('/intakes/{intake}/opname/options/{option}/connections', [SurveyWorkspaceController::class, 'storeConnection'])->name('intakes.workspace.connections.store');
    Route::post('/intakes/{intake}/opname/subjects/{subject}/notes', [SurveyWorkspaceController::class, 'storeNote'])->name('intakes.workspace.notes.store');
    Route::post('/intakes/{intake}/opname/subjects/{subject}/photos', [SurveyWorkspaceController::class, 'storeEvidence'])->name('intakes.workspace.photos.store');
    Route::post('/intakes/{intake}/opname/photo-observations/{record}/confirm', [SurveyWorkspaceController::class, 'confirmObservation'])->name('intakes.workspace.photo-observations.confirm');
    Route::post('/intakes/{intake}/opname/customer-tasks', [SurveyWorkspaceController::class, 'requestContribution'])->name('intakes.workspace.tasks.store');
    Route::post('/intakes/{intake}/opname/customer-tasks/quick', [SurveyWorkspaceController::class, 'requestQuickContribution'])->name('intakes.workspace.tasks.quick');
    Route::post('/intakes/{intake}/opname/routes/{session}/synthesize', [SurveyWorkspaceController::class, 'synthesizeRoute'])->name('intakes.workspace.routes.synthesize');
    Route::post('/intakes/{intake}/opname/routes/{session}/approve', [SurveyWorkspaceController::class, 'approveRoute'])->name('intakes.workspace.routes.approve');
    Route::post('/intakes/{intake}/opname/ai-synthesis', [SurveyWorkspaceController::class, 'synthesizeDossier'])->name('intakes.workspace.synthesis');
    Route::post('/intakes/{intake}/opname/customer-tasks/{task}/send', [SurveyWorkspaceController::class, 'sendProposedTask'])->name('intakes.workspace.tasks.send');
    Route::post('/intakes/{intake}/opname/complete', [SurveyWorkspaceController::class, 'complete'])->name('intakes.workspace.complete');
    Route::post('/intakes/{intake}/opname/outcome', [SurveyWorkspaceController::class, 'recordOutcome'])->name('intakes.workspace.outcome');
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

Route::middleware(['auth', 'verified', 'public.demo.scope', 'dev.access'])
    ->prefix('dev')
    ->name('dev.')
    ->group(function () {
        Route::get('/', DevDashboardController::class)->name('dashboard');
        Route::get('/health', DevHealthController::class)->name('health');
        Route::get('/ai-runs', DevAiRunController::class)->name('ai-runs');
        Route::get('/activity', DevActivityController::class)->name('activity');
        Route::get('/intakes', [DevIntakeController::class, 'index'])->name('intakes');
        Route::get('/intakes/{intake}', [DevIntakeController::class, 'show'])
            ->withTrashed()
            ->name('intakes.show');
    });

Route::middleware(['auth', 'public.demo.scope'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
