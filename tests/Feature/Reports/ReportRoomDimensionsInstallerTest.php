<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeReportDimsIntake(): array
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
    ]);

    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    $save->handle($intake, 'room_length_m', 'room-1', ['number' => 4]);
    $save->handle($intake, 'room_width_m', 'room-1', ['number' => 3.5]);
    $save->handle($intake, 'ceiling_height_m', 'room-1', ['number' => 2.5]);

    $intake = $intake->fresh();
    app(DossierManager::class)->initialize($intake);

    return [$user, $intake->fresh(), $version];
}

function reportDimsText(Intake $intake, $version): string
{
    return trim(preg_replace('/\s+/', ' ', strip_tags(
        app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version),
    )));
}

test('N65: zonder installateurscorrectie blijft Ruimtes de klantmaten tonen', function () {
    [, $intake, $version] = makeReportDimsIntake();

    $text = reportDimsText($intake, $version);

    expect($text)->toContain('3.5')
        ->and($text)->not->toContain('Vervangen door installateur');
});

test('N65: installateursmaten vervangen klantmaten in Ruimtes en blijven consistent met samenvatting', function () {
    [$user, $intake, $version] = makeReportDimsIntake();
    $room = $intake->aircoRooms()->firstOrFail();

    app(AircoSurveyService::class)->updateRoom($intake, $user, $room, [
        'name' => $room->name,
        'length_m' => 4.5,
        'width_m' => 3.5,
        'height_m' => 2.6,
    ]);

    $html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);
    $text = reportDimsText($intake, $version);

    expect($text)->toContain('4,5 (Vervangen door installateur)')
        ->and($text)->toContain('2,6 (Vervangen door installateur)')
        ->and($text)->toContain('15,8 (Vervangen door installateur)')
        ->and($text)->toContain('(15,8 m²)')
        ->and($text)->not->toContain('klant gaf eerder op')
        ->and($html)->not->toMatch('/Oppervlak[^<]*<[^>]*>\s*14\b/');
});

test('N65: alle maten wissen door installateur laat geen oude maten als actueel terugkomen', function () {
    [$user, $intake, $version] = makeReportDimsIntake();
    $room = $intake->aircoRooms()->firstOrFail();

    $room = app(AircoSurveyService::class)->updateRoom($intake, $user, $room, [
        'name' => $room->name,
        'length_m' => null,
        'width_m' => null,
        'height_m' => null,
        'area_m2' => null,
    ]);

    expect($room->dimensions)->toMatchArray([
        'dimensions_source' => 'installer',
        'dimensions_cleared_by_installer' => true,
    ]);

    app(DossierManager::class)->initialize($intake->fresh());
    $text = reportDimsText($intake, $version);

    expect($text)->not->toContain('3.5', '2.5', '(14 m²)', 'Vervangen door installateur', 'klant gaf eerder op')
        ->and($text)->not->toContain('Lengte (m)', 'Breedte (m)', 'Hoogte (m)', 'Oppervlakte (m²)');
});
