<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('resumes the central survey migration without duplicating schema or evidence', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'central-survey-migration-');
    $originalConnection = DB::getDefaultConnection();

    if ($databasePath === false) {
        throw new RuntimeException('Temporary SQLite database could not be created.');
    }

    config(['database.connections.central_survey_migration_test' => [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge('central_survey_migration_test');
    DB::setDefaultConnection('central_survey_migration_test');

    try {
        Artisan::call('migrate:fresh', [
            '--database' => 'central_survey_migration_test',
            '--force' => true,
        ]);

        $intakeId = insertCentralSurveyMigrationEvidence();
        $migration = require database_path('migrations/2026_07_30_120000_create_central_survey_dossier_tables.php');

        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            throw new RuntimeException('Central survey migration is not executable.');
        }

        call_user_func([$migration, 'up']);
        call_user_func([$migration, 'up']);

        $rootId = DB::table('dossier_subjects')
            ->where('intake_id', $intakeId)
            ->where('key', 'survey')
            ->value('id');

        expect($rootId)->not->toBeNull()
            ->and(DB::table('dossier_subjects')->where('intake_id', $intakeId)->where('key', 'survey')->count())->toBe(1)
            ->and(DB::table('dossier_evidence_links')->where('intake_id', $intakeId)->count())->toBe(3)
            ->and(Schema::hasTable('airco_installation_option_placements'))->toBeTrue();

        $reflection = new ReflectionClass($migration);

        expect(strlen((string) $reflection->getConstant('OPTION_PLACEMENT_INSTALLATION_FK')))->toBeLessThanOrEqual(64)
            ->and(strlen((string) $reflection->getConstant('OPTION_PLACEMENT_PLACEMENT_FK')))->toBeLessThanOrEqual(64);
    } finally {
        DB::disconnect('central_survey_migration_test');
        DB::setDefaultConnection($originalConnection);
        DB::purge('central_survey_migration_test');
        @unlink($databasePath);
    }
});

function insertCentralSurveyMigrationEvidence(): int
{
    $now = now();
    $companyId = DB::table('companies')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'slug' => 'migration-recovery-test',
        'name' => 'Migration Recovery Test',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $userId = DB::table('users')->insertGetId([
        'company_id' => $companyId,
        'name' => 'Testinstallateur',
        'email' => 'migration-recovery@example.com',
        'password' => 'test-hash',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $templateId = DB::table('intake_templates')->insertGetId([
        'key' => 'migration-recovery',
        'name' => 'Migration recovery',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $versionId = DB::table('intake_template_versions')->insertGetId([
        'intake_template_id' => $templateId,
        'version' => 1,
        'status' => 'published',
        'published_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $intakeId = DB::table('intakes')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'intake_template_version_id' => $versionId,
        'company_id' => $companyId,
        'created_by' => $userId,
        'status' => 'sent',
        'workflow_mode' => 'customer',
        'customer_name' => 'Testklant',
        'customer_email' => 'testklant@example.com',
        'address_line' => 'Teststraat 1',
        'access_token' => Str::random(64),
        'customer_access_enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('intake_answers')->insert([
        'intake_id' => $intakeId,
        'question_key' => 'room_count',
        'section_instance_key' => null,
        'value' => '2',
        'answered_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('intake_external_facts')->insert([
        'intake_id' => $intakeId,
        'fact_key' => 'building_year',
        'label' => 'Bouwjaar',
        'value' => '1996',
        'source' => 'test',
        'confidence' => 'high',
        'captured_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('intake_uploads')->insert([
        'intake_id' => $intakeId,
        'question_key' => 'room_photos',
        'section_instance_key' => null,
        'disk' => 'local',
        'path' => 'test/migration-recovery.jpg',
        'original_filename' => 'migration-recovery.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
        'sort_order' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $intakeId;
}
