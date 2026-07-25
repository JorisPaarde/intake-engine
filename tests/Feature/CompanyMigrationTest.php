<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('preserves the shared legacy-company access model and rolls back safely', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'company-migration-');
    $originalConnection = DB::getDefaultConnection();

    if ($databasePath === false) {
        throw new RuntimeException('Temporary SQLite database could not be created.');
    }

    config(['database.connections.company_migration_test' => [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge('company_migration_test');
    DB::setDefaultConnection('company_migration_test');

    try {
        Artisan::call('migrate:fresh', [
            '--database' => 'company_migration_test',
            '--force' => true,
        ]);

        $migration = require database_path('migrations/2026_07_25_090000_create_companies_and_assign_tenants.php');

        if (! is_object($migration) || ! method_exists($migration, 'down') || ! method_exists($migration, 'up')) {
            throw new RuntimeException('Company migration is not executable.');
        }

        call_user_func([$migration, 'down']);

        expect(Schema::hasColumn('intakes', 'company_id'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'company_id'))->toBeFalse()
            ->and(Schema::hasTable('companies'))->toBeFalse();

        $legacyIds = insertLegacyCompanyMigrationData();

        call_user_func([$migration, 'up']);

        $userCompanyIds = DB::table('users')
            ->whereIn('id', $legacyIds['users'])
            ->pluck('company_id')
            ->unique();
        $intakeCompanyIds = DB::table('intakes')
            ->whereIn('id', $legacyIds['intakes'])
            ->pluck('company_id')
            ->unique();

        expect($userCompanyIds)->toHaveCount(1)
            ->and($intakeCompanyIds)->toHaveCount(1)
            ->and($intakeCompanyIds->first())->toBe($userCompanyIds->first());

        call_user_func([$migration, 'down']);

        expect(Schema::hasTable('companies'))->toBeFalse();
    } finally {
        DB::disconnect('company_migration_test');
        DB::setDefaultConnection($originalConnection);
        DB::purge('company_migration_test');
        @unlink($databasePath);
    }
});

/**
 * @return array{users: list<int>, intakes: list<int>}
 */
function insertLegacyCompanyMigrationData(): array
{
    $now = now();
    $templateId = DB::table('intake_templates')->insertGetId([
        'key' => 'legacy-test',
        'name' => 'Legacy test',
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
    $userIds = [
        insertLegacyUser('Eerste installateur', 'eerste@example.com', $now),
        insertLegacyUser('Tweede installateur', 'tweede@example.com', $now),
    ];
    $intakeIds = [
        insertLegacyIntake($versionId, $userIds[0], 'Eerste klant', $now),
        insertLegacyIntake($versionId, $userIds[1], 'Tweede klant', $now),
    ];

    return ['users' => $userIds, 'intakes' => $intakeIds];
}

function insertLegacyUser(string $name, string $email, DateTimeInterface $now): int
{
    return DB::table('users')->insertGetId([
        'name' => $name,
        'email' => $email,
        'password' => 'legacy-hash',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function insertLegacyIntake(int $versionId, int $userId, string $customerName, DateTimeInterface $now): int
{
    return DB::table('intakes')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'intake_template_version_id' => $versionId,
        'created_by' => $userId,
        'status' => 'sent',
        'customer_name' => $customerName,
        'customer_email' => Str::slug($customerName).'@example.com',
        'address_line' => 'Teststraat 1',
        'access_token' => Str::random(64),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
