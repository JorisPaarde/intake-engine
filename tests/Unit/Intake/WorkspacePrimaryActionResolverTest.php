<?php

declare(strict_types=1);

use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\WorkspacePrimaryActionResolver;
use App\Enums\DecisionAreaStatus;
use App\Enums\DossierNextAction;
use Illuminate\Support\Collection;

function fakeOpenArea(string $key, DecisionAreaStatus $status = DecisionAreaStatus::Blocked, ?string $blocker = null): DossierDecisionArea
{
    return new DossierDecisionArea([
        'key' => $key,
        'label' => ucfirst($key),
        'status' => $status,
        'blocker' => $blocker,
        'next_action' => DossierNextAction::RequestContribution,
    ]);
}

function bareIntake(): Intake
{
    $intake = new Intake;
    $intake->setRelation('aircoRooms', collect());
    $intake->setRelation('aircoPlacements', collect());
    $intake->setRelation('aircoInstallationOptions', collect());

    return $intake;
}

test('primary action prefers adding a room before viewing open points', function () {
    $intake = bareIntake();

    $action = app(WorkspacePrimaryActionResolver::class)->resolve(
        $intake,
        null,
        false,
        false,
        collect(),
        collect([fakeOpenArea('placement')]),
    );

    expect($action['href'])->toBe('#workspace-rooms')
        ->and($action['label'])->toBe('Ruimte toevoegen')
        ->and($action['label'])->not->toContain('bekijken');
});

test('open area targets deep-link to the matching work block', function () {
    $intake = bareIntake();
    $resolver = app(WorkspacePrimaryActionResolver::class);

    expect($resolver->targetForArea($intake, 'request')['href'])->toBe('#workspace-rooms')
        ->and($resolver->targetForArea($intake, 'capacity')['label'])->toBe('Maten invullen')
        ->and($resolver->targetForArea($intake, 'capacity')['href'])->toBe('#workspace-rooms')
        ->and($resolver->targetForArea($intake, 'placement')['href'])->toBe('#demo-placements')
        ->and($resolver->targetForArea($intake, 'quote')['href'])->toBe('#workspace-complete');
});

test('capacity target deep-links to the first room missing dimensions', function () {
    $intake = bareIntake();
    $complete = (new AircoRoom)->forceFill([
        'id' => 11,
        'dimensions' => ['length_m' => 4.0, 'width_m' => 3.0, 'height_m' => 2.5],
    ]);
    $incomplete = (new AircoRoom)->forceFill([
        'id' => 22,
        'dimensions' => ['length_m' => 4.0],
    ]);
    $intake->setRelation('aircoRooms', collect([$complete, $incomplete]));

    $target = app(WorkspacePrimaryActionResolver::class)->targetForArea($intake, 'capacity');

    expect($target['href'])->toBe('#room-22')
        ->and($target['label'])->toBe('Maten invullen');
});

test('first actionable open area skips quote when other blockers exist', function () {
    /** @var Collection<int, DossierDecisionArea> $areas */
    $areas = collect([
        fakeOpenArea('quote', DecisionAreaStatus::Review),
        fakeOpenArea('power', DecisionAreaStatus::Blocked, 'Meterkastfoto mist'),
        fakeOpenArea('placement', DecisionAreaStatus::Review),
    ]);

    $first = app(WorkspacePrimaryActionResolver::class)->firstActionableOpenArea($areas);

    expect($first?->key)->toBe('placement');
});
