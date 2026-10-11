<?php

declare(strict_types=1);

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeDossierSummaryBuilder;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function summaryBuilderIntake(array $overrides = []): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Completed,
        'customer_name' => 'Testklant Samenvatting',
        'address_line' => 'Teststraat 1',
        'address_postal_code' => '1234AB',
        'address_city' => 'Testdorp',
        'is_demo' => false,
    ], $overrides));
}

function summaryBuilderRoom(Intake $intake, string $key, string $name, int $sort, ?array $dimensions = null): AircoRoom
{
    $manager = app(DossierManager::class);
    $root = $manager->root($intake);
    $subject = $manager->subject(
        $intake,
        'airco.room.'.$key,
        'airco_room',
        $name,
        $root,
        ['legacy_section_instance_key' => $key],
    );

    return AircoRoom::query()->updateOrCreate(
        [
            'intake_id' => $intake->id,
            'key' => $key,
        ],
        [
            'company_id' => $intake->company_id,
            'dossier_subject_id' => $subject->id,
            'name' => $name,
            'name_source' => 'installer',
            'sort_order' => $sort,
            'status' => 'desired',
            'source_type' => 'customer',
            'dimensions' => $dimensions,
        ],
    );
}

function summaryBuilderAnswer(
    Intake $intake,
    string $questionKey,
    array $value,
    ?string $sectionInstanceKey = null,
): void {
    IntakeAnswer::query()->updateOrCreate(
        [
            'intake_id' => $intake->id,
            'question_key' => $questionKey,
            'section_instance_key' => $sectionInstanceKey,
        ],
        [
            'company_id' => $intake->company_id,
            'value' => $value,
            'answered_by_type' => 'customer',
            'answered_at' => now(),
        ],
    );
}

test('N22: korte samenvatting gebruikt opgeslagen ruimtenamen met verdieping en m²', function () {
    $intake = summaryBuilderIntake();
    summaryBuilderAnswer($intake, 'cooling_heating', ['value' => 'cooling']);
    summaryBuilderAnswer($intake, 'building_type', ['value' => 'terraced']);
    summaryBuilderAnswer($intake, 'indoor_unit_count', ['number' => 3]);
    summaryBuilderAnswer($intake, 'room_type', ['value' => 'living_room'], 'room-1');
    summaryBuilderAnswer($intake, 'room_type', ['value' => 'bedroom'], 'room-2');
    summaryBuilderAnswer($intake, 'room_type', ['value' => 'attic'], 'room-3');
    summaryBuilderAnswer($intake, 'desired_planning', ['value' => 'before_summer']);
    summaryBuilderAnswer($intake, 'outdoor_location', ['value' => 'garden']);

    app(DossierManager::class)->initialize($intake);

    summaryBuilderRoom($intake, 'room-1', 'Woonkamer, begane grond', 1, [
        'area_m2' => 30,
        'area_source' => 'customer',
        'area_confidence' => 'high',
        'area_evidence' => '30',
        'dimensions_source' => 'customer',
    ]);
    summaryBuilderRoom($intake, 'room-2', 'Grote slaapkamer, 1e verdieping', 2);
    summaryBuilderRoom($intake, 'room-3', 'Zolderslaapkamer, 2e verdieping', 3);

    $version = $intake->templateVersion()->with(['sections.questions.options'])->firstOrFail();
    $summary = app(IntakeDossierSummaryBuilder::class)->build($intake->fresh(['answers', 'aircoRooms']), $version);

    expect($summary)->toContain(
        '3 ruimtes: Woonkamer, begane grond (30 m²); Grote slaapkamer, 1e verdieping; Zolderslaapkamer, 2e verdieping.',
    )
        ->and($summary)->not->toContain('voor Woonkamer, Slaapkamer, Zolder')
        ->and($summary)->toContain('Voor de zomer')
        ->and($summary)->toContain('Tuin / achtererf')
        ->and($summary)->toContain('Buitenunit:');
});

test('N22: desired_planning als single_choice toont optielabel Voor de zomer', function () {
    $intake = summaryBuilderIntake();
    summaryBuilderAnswer($intake, 'desired_planning', ['value' => 'before_summer']);

    $version = $intake->templateVersion()->with(['sections.questions.options'])->firstOrFail();
    $summary = app(IntakeDossierSummaryBuilder::class)->build($intake->fresh(['answers', 'aircoRooms']), $version);

    expect($summary)->toContain('Gewenste planning: Voor de zomer.');
});

test('N22: zonder AircoRooms blijft fallback op room_type-optielabels', function () {
    $intake = summaryBuilderIntake();
    summaryBuilderAnswer($intake, 'indoor_unit_count', ['number' => 2]);
    summaryBuilderAnswer($intake, 'room_type', ['value' => 'living_room'], 'room-1');
    summaryBuilderAnswer($intake, 'room_type', ['value' => 'bedroom'], 'room-2');

    $version = $intake->templateVersion()->with(['sections.questions.options'])->firstOrFail();
    $summary = app(IntakeDossierSummaryBuilder::class)->build($intake->fresh(['answers', 'aircoRooms']), $version);

    expect($summary)->toContain('Opgegeven omvang: 2 ruimtes voor Woonkamer, Slaapkamer.')
        ->and($summary)->not->toContain('Buitenunit:');
});
