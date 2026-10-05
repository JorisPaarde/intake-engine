<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Support\DossierSynthesisRefreshPresenter;
use App\Domains\Intake\Models\Intake;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Models\User;
use Illuminate\Support\Facades\Log;

test('partial refresh flash uses installer Dutch and hides section keys', function () {
    $user = User::factory()->create();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
    ]);

    $run = AiRun::query()->create([
        'intake_id' => $intake->id,
        'type' => AiRunType::DossierSynthesis,
        'status' => AiRunStatus::Partial,
        'provider' => 'fake',
        'model' => 'fake',
        'prompt_version' => 'dossier-synthesis-v8',
        'input_hash' => hash('sha256', 'test'),
        'output' => [
            'summary' => 'Technische voorzet op basis van beschikbaar bewijs; controleer stroomvoorziening op de meterkastfoto.',
            'placement_proposals' => [
                ['key' => 'p1'],
                ['key' => 'p2'],
            ],
            'option_proposals' => [],
            'exceptions' => [],
            'customer_tasks' => [],
        ],
        'error_message' => 'summary: Samenvatting verzint een elektrische conclusie zonder meterkastbeoordeling. | placement_proposals.0: Een AI-positie verwijst niet naar een geldige subject_reference. | option_proposals.0: Installatieoptie mist volledige koel-/condens-/stroomverbindingen.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    Log::spy();

    $flash = app(DossierSynthesisRefreshPresenter::class)->partialFlash($run);

    expect($flash['message'])
        ->toStartWith('AI-voorstel deels vernieuwd:')
        ->toContain('samenvatting')
        ->toContain('2 posities')
        ->toContain('systeemvoorstel kon niet worden onderbouwd')
        ->not->toContain('placement_proposals')
        ->not->toContain('option_proposals')
        ->not->toContain('subject_reference')
        ->and($flash['technical_detail'])->toContain('option_proposals.0');

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message): bool => str_contains($message, 'partial refresh'));
});

test('partial refresh flash mentions only accepted options when options landed', function () {
    $run = new AiRun([
        'output' => [
            'summary' => 'Oké voorstel',
            'placement_proposals' => [['key' => 'p1']],
            'option_proposals' => [['label' => 'Single-split']],
        ],
        'error_message' => 'placement_proposals.1: Ongeldige evidence_references.',
    ]);

    $flash = app(DossierSynthesisRefreshPresenter::class)->partialFlash($run);

    expect($flash['message'])
        ->toContain('1 positie')
        ->toContain('1 systeemvoorstel')
        ->not->toContain('systeemvoorstel kon niet worden onderbouwd')
        ->not->toContain('placement_proposals');
});
