<?php

declare(strict_types=1);

use App\Console\Commands\EvalInterpretationCommand;
use App\Domains\Intake\Models\IntakeTemplate;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    config(['app.env' => 'testing', 'ai.provider' => 'fake']);
});

test('zonder --migrate en zonder templates faalt met duidelijke fout', function () {
    IntakeTemplate::query()->where('key', 'airco')->delete();
    expect(app(EvalInterpretationCommand::class)->needsTemplates())->toBeTrue();

    $exit = Artisan::call('eval:interpretation', ['--fake' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('--migrate')
        ->and($output)->toContain('template ontbreekt');
});

test('met templates zonder --migrate mag de command verder (fake)', function () {
    expect(IntakeTemplate::query()->where('key', 'airco')->exists())->toBeTrue()
        ->and(app(EvalInterpretationCommand::class)->needsTemplates())->toBeFalse();

    $exit = Artisan::call('eval:interpretation', ['--fake' => true, '--repeats' => 1]);

    expect($exit)->toBe(0);
});
