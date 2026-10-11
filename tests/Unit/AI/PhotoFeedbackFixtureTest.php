<?php

declare(strict_types=1);

use App\Domains\AI\Eval\EvalFixturePrivacyScan;
use Symfony\Component\Finder\Finder;

test('photo_feedback fixtures have required schema fields', function () {
    $dir = __DIR__.'/../../Eval/fixtures/photo_feedback';
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
                'question_key',
                'profile_name',
                'model_output',
                'code_path',
                'expected',
            ])
            ->and($decoded['kind'])->toBe('photo_feedback')
            ->and($decoded['question_key'])->toBe('drain_photo')
            ->and($decoded['profile_name'])->toBe('drain')
            ->and($decoded['expected'])->toHaveKey('assessment_status');

        $ids[] = $decoded['id'];
    }

    expect($ids)->toContain('pf-n16-drain-livingroom-as-indoor')
        ->and($ids)->toContain('pf-n16-drain-room-subject-match-no')
        ->and($ids)->toContain('pf-n16-drain-correct-downspout')
        ->and(count($ids))->toBe(count(array_unique($ids)));
});

test('photo_feedback fixtures pass privacy scan', function () {
    $privacy = new EvalFixturePrivacyScan;
    $dir = __DIR__.'/../../Eval/fixtures/photo_feedback';
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
