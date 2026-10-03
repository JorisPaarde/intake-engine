<?php

declare(strict_types=1);

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

/**
 * @return object{up: callable, down: callable}
 */
function installerOwnershipBackfillMigration(): object
{
    $migration = require database_path('migrations/2026_10_03_160000_backfill_installer_dimensions_and_use_type_source.php');

    if (! is_object($migration) || ! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
        throw new RuntimeException('Installer ownership backfill migration is not executable.');
    }

    return $migration;
}

test('backfill: template_bridge met airco_room_updated krijgt dimensions_source en use_type_source installer', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_email' => 'legacy-dims@example.com',
    ]);

    app(DossierManager::class)->initialize($intake);
    $root = $intake->fresh()->dossierSubjects()->whereNull('parent_id')->firstOrFail();

    $roomId = DB::table('airco_rooms')->insertGetId([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'dossier_subject_id' => $root->id,
        'key' => 'room-9',
        'name' => 'Legacy kamer',
        'use_type' => 'office',
        'use_type_source' => null,
        'sort_order' => 9,
        'status' => 'desired',
        'source_type' => 'template_bridge',
        'source_id' => null,
        'dimensions' => json_encode(['length_m' => 6.0, 'width_m' => 4.0, 'height_m' => 2.6], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    IntakeActivityEvent::query()->create([
        'intake_id' => $intake->id,
        'actor_type' => 'user',
        'actor_id' => $intake->created_by,
        'event' => 'airco_room_updated',
        'properties' => ['room_id' => $roomId],
        'created_at' => now(),
    ]);

    $migration = installerOwnershipBackfillMigration();
    $migration->up();
    $migration->up(); // idempotent

    $room = AircoRoom::query()->findOrFail($roomId);
    expect($room->dimensions['dimensions_source'] ?? null)->toBe('installer')
        ->and($room->use_type_source)->toBe('installer');

    // down is no-op: markers blijven staan.
    $migration->down();
    $room->refresh();
    expect($room->dimensions['dimensions_source'] ?? null)->toBe('installer')
        ->and($room->use_type_source)->toBe('installer');
});

test('backfill: verouderd use_type door klantcorrectie wordt niet als installer gemarkeerd', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_email' => 'legacy-use-type@example.com',
    ]);

    app(DossierManager::class)->initialize($intake);
    $root = $intake->fresh()->dossierSubjects()->whereNull('parent_id')->firstOrFail();

    // Klantcorrectie op room_type (prefill_source null) → bedroom, maar room heeft nog office.
    IntakeAnswer::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'room_type',
        'section_instance_key' => 'room-7',
        'value' => ['value' => 'bedroom'],
        'prefill_source' => null,
        'answered_at' => now(),
    ]);

    $roomId = DB::table('airco_rooms')->insertGetId([
        'intake_id' => $intake->id,
        'company_id' => $intake->company_id,
        'dossier_subject_id' => $root->id,
        'key' => 'room-7',
        'name' => 'Legacy kantoor',
        'use_type' => 'office',
        'use_type_source' => null,
        'sort_order' => 7,
        'status' => 'desired',
        'source_type' => 'template_bridge',
        'source_id' => null,
        'dimensions' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Geen airco_room_updated — mismatch mag niet als installer gelden.
    installerOwnershipBackfillMigration()->up();

    $room = AircoRoom::query()->findOrFail($roomId);
    expect($room->use_type_source)->toBeNull()
        ->and($room->use_type)->toBe('office');

    // Volgende sync corrigeert naar het klantantwoord.
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $room = AircoRoom::query()->findOrFail($roomId);
    expect($room->use_type)->toBe('bedroom')
        ->and($room->use_type_source)->toBe('customer');
});
