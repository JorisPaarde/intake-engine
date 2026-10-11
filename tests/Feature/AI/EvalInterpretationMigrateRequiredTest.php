<?php

declare(strict_types=1);

use App\Console\Commands\EvalInterpretationCommand;
use App\Domains\AI\Eval\EvalReportWriter;
use App\Domains\Intake\Models\IntakeTemplate;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    config([
        'app.env' => 'testing',
        'ai.provider' => 'fake',
        'ai.eval.output_dir' => 'storage/framework/testing/eval',
    ]);
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

test('fake eval run leaves tracked baseline and HISTORY untouched', function () {
    $trackedBaseline = base_path('tests/Eval/baseline');
    $trackedHistory = base_path('tests/Eval/results/HISTORY.md');

    $baselineBefore = collect(File::files($trackedBaseline))
        ->mapWithKeys(fn ($f) => [$f->getFilename() => hash_file('sha256', $f->getPathname())])
        ->all();
    $historyBefore = File::get($trackedHistory);
    $historyHashBefore = hash('sha256', $historyBefore);

    $tmp = storage_path('framework/testing/eval-fake-'.uniqid('', true));
    File::ensureDirectoryExists($tmp);

    $exit = Artisan::call('eval:interpretation', [
        '--fake' => true,
        '--repeats' => 1,
        '--output' => $tmp,
    ]);

    expect($exit)->toBe(0);

    $baselineAfter = collect(File::files($trackedBaseline))
        ->mapWithKeys(fn ($f) => [$f->getFilename() => hash_file('sha256', $f->getPathname())])
        ->all();

    expect($baselineAfter)->toBe($baselineBefore)
        ->and(hash('sha256', File::get($trackedHistory)))->toBe($historyHashBefore)
        ->and(File::exists($tmp.'/baseline'))->toBeFalse()
        ->and(File::exists($tmp.'/results/HISTORY.md'))->toBeFalse()
        ->and(File::isDirectory($tmp.'/results'))->toBeTrue()
        ->and(app(EvalReportWriter::class)->outputRoot())->toBe(rtrim($tmp, '/'));

    File::deleteDirectory($tmp);
});
