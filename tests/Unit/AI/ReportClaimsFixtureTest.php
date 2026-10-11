<?php

declare(strict_types=1);

use App\Domains\AI\Eval\EvalFixturePrivacyScan;
use Symfony\Component\Finder\Finder;

test('report_claims fixtures have required schema fields', function () {
    $dir = __DIR__.'/../../Eval/fixtures/report_claims';
    expect(is_dir($dir))->toBeTrue();

    $finder = (new Finder)->files()->name('*.json')->in($dir)->sortByName();
    $ids = [];

    foreach ($finder as $file) {
        $decoded = json_decode($file->getContents(), true);
        expect($decoded)->toBeArray()
            ->and($decoded)->toHaveKeys([
                'id',
                'kind',
                'source_kind',
                'summary_input',
                'attention_input',
                'expected',
            ])
            ->and($decoded['kind'])->toBe('report_claims')
            ->and($decoded['source_kind'])->toBeIn(['verbatim_notion', 'variant', 'verbatim_trace', 'verbatim_config', 'reconstructed'])
            ->and($decoded['summary_input'])->toBeArray()
            ->and($decoded['attention_input'])->toBeArray()
            ->and($decoded['expected'])->toBeArray()
            ->and($decoded['expected'])->toHaveKey('claims')
            ->and($decoded['expected']['claims'])->toBeArray()
            ->and($decoded['expected']['claims'])->not->toBeEmpty();

        foreach ($decoded['expected']['claims'] as $claim) {
            expect($claim)->toBeArray()
                ->and($claim)->toHaveKeys(['id', 'concept', 'allowed'])
                ->and($claim['allowed'])->toBeIn(['assert', 'hedged_only', 'forbidden']);
        }

        // Prod does not store photo_assessments[] on the fusebox external fact.
        $summaryFact = $decoded['summary_input']['external_facts']['fusebox_photo_assessment']['value'] ?? null;
        if (is_array($summaryFact)) {
            expect($summaryFact)->not->toHaveKey('photo_assessments');
        }

        // Summary payload mirrors attention: uploads[] from stored content_assessment.
        expect($decoded['summary_input'])->toHaveKey('uploads')
            ->and($decoded['summary_input']['uploads'])->toBeArray();
        foreach ($decoded['summary_input']['uploads'] as $row) {
            expect($row)->toBeArray()
                ->and($row)->toHaveKeys(['question_label', 'order', 'content_assessment'])
                ->and($row['order'])->toBeInt()
                ->and($row['order'])->toBeGreaterThan(0)
                ->and($row['content_assessment'])->toBeArray()
                ->and($row['content_assessment'])->toHaveKey('status')
                ->and($row['content_assessment'])->not->toHaveKey('phase')
                ->and($row['content_assessment'])->not->toHaveKey('empty_module_space');
        }

        $ids[] = $decoded['id'];
    }

    expect($ids)->toContain('rc-kt11-fusebox-two-photos-conflict')
        ->and($ids)->toContain('rc-kt11-fusebox-hedged-single')
        ->and($ids)->toContain('rc-fusebox-blanking-plates')
        ->and($ids)->toContain('rc-fusebox-clear-3ph-high')
        ->and($ids)->toContain('rc-no-fusebox-photo')
        ->and($ids)->toContain('rc-n14-attic-vs-office-furnishing')
        ->and($ids)->toContain('rc-n14-phase-unknown-vs-ai')
        ->and($ids)->toContain('rc-n14-phase-stated-vs-ai')
        ->and($ids)->toContain('rc-n20-fusebox-unsharp-then-sharp-no-conflict')
        ->and($ids)->toContain('rc-n20-fusebox-two-readable-photos-disagree')
        ->and(count($ids))->toBe(count(array_unique($ids)));
});

test('N20 fixtures encode unsharp-then-sharp vs readable conflict expectations', function () {
    $n20 = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n20-fusebox-unsharp-then-sharp-no-conflict.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $control = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-n20-fusebox-two-readable-photos-disagree.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $kt11 = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-kt11-fusebox-two-photos-conflict.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $n20Conflict = collect($n20['expected']['claims'])->firstWhere('id', 'photo_conflict');
    $controlConflict = collect($control['expected']['claims'])->firstWhere('id', 'photo_conflict');
    $kt11Conflict = collect($kt11['expected']['claims'])->firstWhere('id', 'photo_conflict');

    expect($n20['source_kind'])->toBe('verbatim_notion')
        ->and($n20Conflict['allowed'])->toBe('forbidden')
        ->and($control['source_kind'])->toBe('variant')
        ->and($controlConflict['allowed'])->toBe('assert')
        ->and($kt11Conflict['allowed'])->toBe('forbidden')
        ->and($n20['summary_input']['uploads'][0]['content_assessment']['status'])->toBe('needs_clearer')
        ->and($n20['summary_input']['uploads'][1]['content_assessment']['status'])->toBe('ok')
        ->and($control['summary_input']['uploads'][0]['content_assessment']['status'])->toBe('ok')
        ->and($control['summary_input']['uploads'][1]['content_assessment']['status'])->toBe('ok')
        ->and($control['summary_input']['external_facts']['fusebox_photo_assessment']['value'])
        ->not->toHaveKey('photo_assessments');
});

test('report_claims fixtures pass privacy scan', function () {
    $privacy = new EvalFixturePrivacyScan;
    $dir = __DIR__.'/../../Eval/fixtures/report_claims';
    $hits = [];

    foreach ((new Finder)->files()->name('*.json')->in($dir) as $file) {
        $data = json_decode($file->getContents(), true);
        expect($data)->toBeArray();
        $blob = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach ($privacy->hits($blob) as $hit) {
            $hits[] = $file->getFilename().': '.$hit;
        }
    }

    expect($hits)->toBe([]);
});

test('clear-3ph fixture keeps prod fact confidence medium with high only on photo assessment', function () {
    $decoded = json_decode(
        (string) file_get_contents(__DIR__.'/../../Eval/fixtures/report_claims/rc-fusebox-clear-3ph-high.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($decoded['summary_input']['external_facts']['fusebox_photo_assessment']['confidence'])->toBe('medium')
        ->and($decoded['summary_input']['external_facts']['fusebox_photo_assessment']['value']['confidence'])->toBe('high')
        ->and($decoded['summary_input']['uploads'][0]['content_assessment']['status'])->toBe('ok')
        ->and($decoded['attention_input']['uploads'][0]['content_assessment']['status'])->toBe('ok');
});
