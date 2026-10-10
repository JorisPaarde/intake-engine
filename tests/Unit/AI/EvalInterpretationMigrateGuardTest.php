<?php

declare(strict_types=1);

use App\Console\Commands\EvalInterpretationCommand;
use Tests\TestCase;

uses(TestCase::class);

test('liveEnvBlockReason weigert production en staging', function () {
    $command = app(EvalInterpretationCommand::class);

    config(['app.env' => 'production', 'database.default' => 'sqlite']);
    expect($command->liveEnvBlockReason())->toContain('production')
        ->and($command->liveEnvBlockReason())->toContain('eval:interpretation');

    config(['app.env' => 'staging', 'database.default' => 'sqlite']);
    expect($command->liveEnvBlockReason())->toContain('staging');
});

test('liveEnvBlockReason staat local, testing en prod-alias toe', function () {
    $command = app(EvalInterpretationCommand::class);

    config(['app.env' => 'local']);
    expect($command->liveEnvBlockReason())->toBeNull();

    config(['app.env' => 'testing']);
    expect($command->liveEnvBlockReason())->toBeNull();

    // Alleen production/staging — geen 'prod'-alias.
    config(['app.env' => 'prod']);
    expect($command->liveEnvBlockReason())->toBeNull();
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
