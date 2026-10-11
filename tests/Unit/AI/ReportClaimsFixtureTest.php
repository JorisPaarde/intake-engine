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
            ->and($decoded['expected'])->toHaveKey('must_not_assert');

        $ids[] = $decoded['id'];
    }

    expect($ids)->toContain('rc-kt11-fusebox-two-photos-conflict')
        ->and($ids)->toContain('rc-kt11-fusebox-hedged-single')
        ->and($ids)->toContain('rc-fusebox-blanking-plates')
        ->and($ids)->toContain('rc-fusebox-clear-3ph-high')
        ->and($ids)->toContain('rc-no-fusebox-photo')
        ->and(count($ids))->toBe(count(array_unique($ids)));
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
