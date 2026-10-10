<?php

declare(strict_types=1);

use App\Console\Commands\EvalInterpretationCommand;
use Tests\TestCase;

uses(TestCase::class);

test('freshMigrateBlockReason weigert production en staging', function () {
    $command = app(EvalInterpretationCommand::class);

    config(['app.env' => 'production', 'database.default' => 'sqlite']);
    expect($command->freshMigrateBlockReason())->toContain('production');

    config(['app.env' => 'staging', 'database.default' => 'sqlite']);
    expect($command->freshMigrateBlockReason())->toContain('staging');
});

test('freshMigrateBlockReason weigert niet-sqlite', function () {
    $command = app(EvalInterpretationCommand::class);

    config(['app.env' => 'local', 'database.default' => 'mysql']);
    expect($command->freshMigrateBlockReason())->toContain('sqlite')
        ->and($command->freshMigrateBlockReason())->toContain('mysql');
});

test('freshMigrateBlockReason staat lokale sqlite toe', function () {
    $command = app(EvalInterpretationCommand::class);

    config(['app.env' => 'local', 'database.default' => 'sqlite']);
    expect($command->freshMigrateBlockReason())->toBeNull();
});
