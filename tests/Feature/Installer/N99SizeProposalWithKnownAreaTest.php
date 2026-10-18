<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\DossierOverviewBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\ContributionMode;
use App\Enums\DossierRecordStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

/**
 * @param  array{area?: bool, size?: string|null, sizeSource?: string|null}  $opts
 */
function n99SizeIntake(array $opts): Intake
{
    $intake = app(CreateIntake::class)->handle(User::factory()->create(), [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Maat Test',
        'customer_email' => 'maat@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    if ($opts['area'] ?? false) {
        $save->handle($intake, 'room_area_m2', 'room-1', ['number' => 12]);
    }
    if (($opts['size'] ?? null) !== null) {
        $save->handle($intake, 'room_size_indication', 'room-1', ['value' => $opts['size']], array_key_exists('sizeSource', $opts) ? $opts['sizeSource'] : PrefillSources::AI_PHOTO);
    }
    app(DossierManager::class)->initialize($intake->fresh());

    return $intake->fresh();
}

function n99SizeRecord(Intake $intake): ?DossierRecord
{
    $answer = IntakeAnswer::query()->where('intake_id', $intake->id)
        ->where('question_key', 'room_size_indication')->first();

    return $answer === null ? null : DossierRecord::query()
        ->where('source_type', 'intake_answer')->where('source_id', $answer->id)->first();
}

/** @return array<string, string> */
function n99AreaStatuses(Intake $intake): array
{
    $overview = app(DossierOverviewBuilder::class)->build($intake);

    return $overview['areas']->mapWithKeys(fn ($area): array => [$area->key => $area->status->value])->all()
        + ['ready_count' => (string) $overview['ready_count']];
}

test('N99: geen AI-groottevoorstel in het dossier als m² bekend is', function () {
    $record = n99SizeRecord(n99SizeIntake(['area' => true, 'size' => 'small']));

    expect($record === null || $record->status === DossierRecordStatus::Superseded)->toBeTrue();
});

test('N99: zonder m² blijft het AI-groottevoorstel staan', function () {
    $record = n99SizeRecord(n99SizeIntake(['area' => false, 'size' => 'small']));

    expect($record)->not->toBeNull()
        ->and($record->status)->not->toBe(DossierRecordStatus::Superseded);
});

test('N99: een klantantwoord over de grootte blijft in het dossier, ook met m²', function () {
    $record = n99SizeRecord(n99SizeIntake(['area' => true, 'size' => 'medium', 'sizeSource' => null]));

    expect($record)->not->toBeNull()
        ->and($record->status)->not->toBe(DossierRecordStatus::Superseded);
});

test('N99: bestaand groottevoorstel vervalt zodra m² bekend wordt', function () {
    $intake = n99SizeIntake(['area' => false, 'size' => 'small']);
    expect(n99SizeRecord($intake)->status)->not->toBe(DossierRecordStatus::Superseded);

    app(SaveIntakeAnswer::class)->handle($intake, 'room_area_m2', 'room-1', ['number' => 12]);
    app(DossierManager::class)->initialize($intake->fresh());

    expect(n99SizeRecord($intake)->fresh()->status)->toBe(DossierRecordStatus::Superseded);
});

test('N99: Klaar voor offerte verandert niet door het weglaten van het groottevoorstel', function () {
    $withProposal = n99AreaStatuses(n99SizeIntake(['area' => true, 'size' => 'small']));
    $withoutProposal = n99AreaStatuses(n99SizeIntake(['area' => true, 'size' => null]));

    expect($withProposal)->toBe($withoutProposal);
});
