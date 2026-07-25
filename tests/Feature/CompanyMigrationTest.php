<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

it('rolls back company tenancy in foreign-key-safe order', function () {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_07_25_090000_create_companies_and_assign_tenants.php');

    $migration->down();

    expect(Schema::hasColumn('intakes', 'company_id'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'company_id'))->toBeFalse()
        ->and(Schema::hasTable('companies'))->toBeFalse();
});
