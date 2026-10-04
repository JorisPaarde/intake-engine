<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\FactProvenance;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\ContributionMode;
use App\Enums\DossierRecordStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

test('inferred risk prefill syncs as ai_assumption with Dutch labels and no raw keys in value display fields', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Aanname Klant',
        'customer_email' => 'aanname@example.com',
        'address_line' => 'Assumptielaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'noise_sensitive',
        null,
        ['bool' => true],
        PrefillSources::AI_TEXT_SUGGESTION,
        FactProvenance::Inferred,
    );

    $record = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('method', 'ai_assumption')
        ->where('key', 'answer.noise_sensitive')
        ->sole();

    expect($record->status)->toBe(DossierRecordStatus::Proposed)
        ->and($record->value['_field_label'] ?? null)->toBeString()
        ->and($record->value['_field_label'])->not->toBe('noise_sensitive')
        ->and($record->value['_field_label'])->toContain('geluid')
        ->and($record->value['_display_value'] ?? null)->toBe('ja')
        ->and($record->value['_provenance_label'] ?? null)->toBe('aanname')
        ->and($record->value['_source_label'] ?? null)->toBe('afgeleid, niet bevestigd')
        ->and($record->value['_confidence_label'] ?? null)->toContain('%')
        ->and($record->value['_status_label'] ?? null)->toBe('nog te bevestigen')
        ->and(json_encode($record->value, JSON_UNESCAPED_UNICODE))->not->toContain('noise_sensitive');
});

test('AI proposals for installer show Dutch field label value source confidence without raw keys or enums', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Voorstel Klant',
        'customer_email' => 'voorstel@example.com',
        'address_line' => 'Voorstellaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'free_group_known',
        null,
        ['value' => 'yes'],
        PrefillSources::AI_PHOTO,
    );

    app(DossierManager::class)->initialize($intake->fresh());

    $record = DossierRecord::query()
        ->where('intake_id', $intake->id)
        ->where('key', 'answer.free_group_known')
        ->sole();

    expect($record->method)->toBe('ai_proposal')
        ->and($record->value['_field_label'] ?? null)->toBeString()
        ->and($record->value['_field_label'])->not->toBe('free_group_known')
        ->and(mb_strtolower((string) $record->value['_field_label']))->toContain('vrije')
        ->and($record->value['_display_value'] ?? null)->toBe('Ja')
        ->and($record->value['_display_value'])->not->toBe('yes')
        ->and($record->value['_source_label'] ?? null)->toBe('foto')
        ->and($record->value['_confidence_label'] ?? null)->toContain('%');

    $version = $intake->fresh()->templateVersion()
        ->with(['sections.questions.options', 'sections.questions.rules'])
        ->firstOrFail();
    $check = app(CompletenessChecker::class)->check($intake->fresh(), $version);
    $point = collect($check['attention_points'])->firstWhere('code', 'electrical_provision_open');

    expect($point)->not->toBeNull();
    $label = (string) $point['label'];
    expect($label)->toContain('AI-voorstel')
        ->and(mb_strtolower($label))->toContain('vrije')
        ->and($label)->toContain('Ja')
        ->and($label)->toContain('bron:')
        ->and($label)->toContain('zekerheid:')
        ->and($label)->not->toContain('free_group_known')
        ->and($label)->not->toMatch('/\byes\b/')
        ->and($label)->not->toContain('ai_photo');
});
