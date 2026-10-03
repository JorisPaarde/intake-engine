<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\DossierRecord;
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
        ->and($record->value['_source_label'] ?? null)->toBe('aanname')
        ->and($record->value['_confidence_label'] ?? null)->toBe('middel')
        ->and(json_encode($record->value, JSON_UNESCAPED_UNICODE))->not->toContain('noise_sensitive');
});
