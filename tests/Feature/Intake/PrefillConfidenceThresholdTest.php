<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\FactSource;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\ContributionMode;
use App\Enums\DossierRecordStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

test('value under fact confidence threshold becomes a customer confirmation question not known fact', function () {
    config(['intake.fact_confidence_threshold' => 80]);

    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Drempel Klant',
        'customer_email' => 'drempel@example.com',
        'address_line' => 'Drempellaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
        'request_reason' => 'We willen airco op het balkon van de slaapkamer.',
    ]);

    // Simulated AI fill that looks strong but sits under the threshold after quote failure.
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'noise_sensitive',
        null,
        ['bool' => true],
        PrefillSources::AI_TEXT_SUGGESTION,
        FactProvenance::Inferred,
        70,
        'buren dichtbij',
        FactSource::Derived,
    );

    $version = $intake->fresh()->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $steps = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version));
    $summary = $steps->firstWhere('kind', 'known_summary');
    $summaryKeys = collect($summary['known_items'] ?? [])->pluck('question_key')->all();

    expect($summaryKeys)->not->toContain('noise_sensitive')
        ->and($steps->pluck('question_key')->all())->toContain('noise_sensitive');
});

test('confidence percentage and source are visible on installer dossier records', function () {
    config(['intake.fact_confidence_threshold' => 80]);

    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Dossier Klant',
        'customer_email' => 'dossier-conf@example.com',
        'address_line' => 'Dossierlaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'cooling_heating',
        null,
        ['value' => 'cooling'],
        PrefillSources::AI_TEXT_SUGGESTION,
        FactProvenance::Inferred,
        70,
        'koelen',
        FactSource::Derived,
    );

    app(DossierManager::class)->initialize($intake->fresh());

    $record = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', 'answer.cooling_heating')
        ->sole();

    expect($record->status)->toBe(DossierRecordStatus::Proposed)
        ->and($record->method)->toBe('ai_assumption')
        ->and($record->value['_confidence_percent'] ?? null)->toBe(70)
        ->and($record->value['_confidence_label'] ?? null)->toContain('70')
        ->and($record->value['_source_label'] ?? null)->toBe('afgeleid, niet bevestigd')
        ->and($record->value['_status_label'] ?? null)->toBe('nog te bevestigen')
        ->and($record->confidence)->toBe(0.7);
});

test('balkon-only known summary never shows Buren dichtbij as fact', function () {
    config(['intake.fact_confidence_threshold' => 80]);

    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Balkon Klant',
        'customer_email' => 'balkon@example.com',
        'address_line' => 'Balkonlaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
        'request_reason' => 'We willen een airco voor de slaapkamer met balkon.',
    ]);

    // Strong AI_TEXT with inferred provenance must not appear as known fact.
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'noise_sensitive',
        null,
        ['bool' => true],
        PrefillSources::AI_TEXT,
        FactProvenance::Inferred,
        90,
        'buren dichtbij',
        FactSource::Derived,
    );

    // Stated cooling with real quote may appear.
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'cooling_heating',
        null,
        ['value' => 'cooling'],
        PrefillSources::AI_TEXT,
        FactProvenance::Stated,
        90,
        'airco',
        FactSource::CustomerAnswer,
    );

    $version = $intake->fresh()->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $steps = collect(app(IntakeStepBuilder::class)->build($intake->fresh(), $version));
    $summary = $steps->firstWhere('kind', 'known_summary');
    $items = collect($summary['known_items'] ?? []);

    expect($items->pluck('question_key')->all())->not->toContain('noise_sensitive');

    $noiseStep = $steps->firstWhere('question_key', 'noise_sensitive');
    expect($noiseStep)->not->toBeNull();
});
