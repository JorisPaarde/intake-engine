<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('backfills structured address fields and repairs the duplicated house number safely', function () {
    $databasePath = tempnam(sys_get_temp_dir(), 'structured-address-migration-');
    $originalConnection = DB::getDefaultConnection();

    if ($databasePath === false) {
        throw new RuntimeException('Temporary SQLite database could not be created.');
    }

    config(['database.connections.structured_address_migration_test' => [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge('structured_address_migration_test');
    DB::setDefaultConnection('structured_address_migration_test');

    try {
        Artisan::call('migrate:fresh', [
            '--database' => 'structured_address_migration_test',
            '--force' => true,
        ]);

        $migration = require database_path('migrations/2026_07_30_140000_add_structured_address_to_intakes_table.php');

        if (! is_object($migration) || ! method_exists($migration, 'down') || ! method_exists($migration, 'up')) {
            throw new RuntimeException('Structured address migration is not executable.');
        }

        call_user_func([$migration, 'down']);

        expect(Schema::hasColumn('intakes', 'address_house_number'))->toBeFalse()
            ->and(Schema::hasColumn('intakes', 'address_house_number_addition'))->toBeFalse();

        $now = now();
        $companyId = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'slug' => 'structured-address-migration',
            'name' => 'Structured Address Migration',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = DB::table('users')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Adresinstallateur',
            'email' => 'adresmigratie@example.com',
            'password' => 'test-hash',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $templateId = DB::table('intake_templates')->insertGetId([
            'key' => 'structured-address-migration',
            'name' => 'Structured address migration',
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

        $duplicatedId = insertLegacyAddressIntake(
            $companyId,
            $userId,
            $versionId,
            'Bernadottelaan, 273, 273',
            'bernadotte-migration@example.com',
            $now,
        );
        $canonicalId = insertLegacyAddressIntake(
            $companyId,
            $userId,
            $versionId,
            'Teststraat 10-A',
            'canonical-migration@example.com',
            $now,
        );

        call_user_func([$migration, 'up']);
        call_user_func([$migration, 'up']);

        $duplicated = DB::table('intakes')->where('id', $duplicatedId)->first();
        $canonical = DB::table('intakes')->where('id', $canonicalId)->first();

        expect(Schema::hasColumn('intakes', 'address_house_number'))->toBeTrue()
            ->and(Schema::hasColumn('intakes', 'address_house_number_addition'))->toBeTrue()
            ->and($duplicated?->address_line)->toBe('Bernadottelaan 273')
            ->and((int) $duplicated?->address_house_number)->toBe(273)
            ->and($duplicated?->address_house_number_addition)->toBeNull()
            ->and((int) $canonical?->address_house_number)->toBe(10)
            ->and($canonical?->address_house_number_addition)->toBe('A');

        call_user_func([$migration, 'down']);

        expect(Schema::hasColumn('intakes', 'address_house_number'))->toBeFalse()
            ->and(Schema::hasColumn('intakes', 'address_house_number_addition'))->toBeFalse();
    } finally {
        DB::disconnect('structured_address_migration_test');
        DB::setDefaultConnection($originalConnection);
        DB::purge('structured_address_migration_test');
        @unlink($databasePath);
    }
});

function insertLegacyAddressIntake(
    int $companyId,
    int $userId,
    int $versionId,
    string $addressLine,
    string $email,
    DateTimeInterface $now,
): int {
    return DB::table('intakes')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'intake_template_version_id' => $versionId,
        'company_id' => $companyId,
        'created_by' => $userId,
        'status' => 'sent',
        'workflow_mode' => 'customer',
        'customer_name' => 'Adresklant',
        'customer_email' => $email,
        'address_line' => $addressLine,
        'address_postal_code' => '2037GR',
        'address_city' => 'Haarlem',
        'access_token' => Str::random(64),
        'customer_access_enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
