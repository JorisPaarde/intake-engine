<?php

declare(strict_types=1);

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\ContextualCustomerTaskBuilder;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\DecisionAreaStatus;
use App\Enums\DossierNextAction;
use App\Enums\FollowUpItemType;

function builderIntakeWithRooms(iterable $rooms = []): Intake
{
    $intake = new Intake;
    $intake->setRelation('aircoRooms', collect($rooms));
    $intake->setRelation('aircoPlacements', collect());
    $intake->setRelation('aircoInstallationOptions', collect());

    return $intake;
}

test('room draft asks for dimensions and names the room', function () {
    $room = (new AircoRoom)->forceFill([
        'id' => 7,
        'name' => 'Slaapkamer ouders',
        'use_type' => 'bedroom',
        'dimensions' => null,
        'dossier_subject_id' => 42,
    ]);

    $draft = app(ContextualCustomerTaskBuilder::class)->forRoom($room);

    expect($draft)->toMatchArray([
        'type' => FollowUpItemType::Text->value,
        'decision_area_key' => 'capacity',
        'dossier_subject_id' => 42,
    ])
        ->and($draft['prompt'])->toContain('Slaapkamer ouders')
        ->and($draft['prompt'])->toContain('lengte en breedte')
        ->and($draft['prompt'])->not->toContain('hoogte');
});

test('complete room has no customer ask', function () {
    $room = (new AircoRoom)->forceFill([
        'id' => 8,
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'dimensions' => ['length_m' => 4, 'width_m' => 3],
        'dossier_subject_id' => 9,
    ]);

    expect(app(ContextualCustomerTaskBuilder::class)->forRoom($room))->toBeNull();
});

test('connection needing evidence drafts a photo ask', function () {
    $connection = (new AircoConnection)->forceFill([
        'id' => 3,
        'label' => 'Koelleiding slaapkamer',
        'type' => AircoConnectionType::Refrigerant,
        'status' => AircoConnectionStatus::NeedsEvidence,
        'dossier_subject_id' => 15,
    ]);
    $connection->setRelation('fromPlacement', null);
    $connection->setRelation('toPlacement', null);
    $connection->setRelation('routeSession', null);

    $draft = app(ContextualCustomerTaskBuilder::class)->forConnection($connection);

    expect($draft)->toMatchArray([
        'type' => FollowUpItemType::Photo->value,
        'decision_area_key' => 'refrigerant',
        'dossier_subject_id' => 15,
    ])
        ->and($draft['prompt'])->toContain('Koelleiding slaapkamer');
});

test('approved connection has no customer ask', function () {
    $connection = (new AircoConnection)->forceFill([
        'id' => 4,
        'label' => 'Goedgekeurde route',
        'type' => AircoConnectionType::Condensate,
        'status' => AircoConnectionStatus::Approved,
        'dossier_subject_id' => 16,
    ]);
    $connection->setRelation('fromPlacement', null);
    $connection->setRelation('toPlacement', null);
    $connection->setRelation('routeSession', null);

    expect(app(ContextualCustomerTaskBuilder::class)->forConnection($connection))->toBeNull();
});

test('placement multi-split blocker is not customer-suitable', function () {
    $intake = builderIntakeWithRooms();
    $area = new DossierDecisionArea([
        'key' => 'placement',
        'label' => 'Plaatsing',
        'status' => DecisionAreaStatus::Blocked,
        'blocker' => 'Kies eerst multi-split of singles met binnenunit en buitenunit.',
        'next_action' => DossierNextAction::RequestContribution,
    ]);

    expect(app(ContextualCustomerTaskBuilder::class)->forDecisionArea($intake, $area))->toBeNull();
});

test('placement around-house photo blocker is customer-suitable', function () {
    $intake = builderIntakeWithRooms();
    $area = new DossierDecisionArea([
        'key' => 'placement',
        'label' => 'Plaatsing',
        'status' => DecisionAreaStatus::Blocked,
        'blocker' => 'Voeg foto’s rondom het huis toe (gevel, tuin of montageplek). Een luchtfoto volstaat niet.',
        'next_action' => DossierNextAction::RequestContribution,
    ]);

    $draft = app(ContextualCustomerTaskBuilder::class)->forDecisionArea($intake, $area);

    expect($draft)->toMatchArray([
        'type' => FollowUpItemType::Photo->value,
        'decision_area_key' => 'placement',
    ])
        ->and($draft['prompt'])->toContain('rondom het huis');
});

test('photo suggestion drafts a retake ask for the subject', function () {
    $subject = (new DossierSubject)->forceFill([
        'id' => 21,
        'type' => 'airco_room',
        'label' => 'Slaapkamer',
        'meta' => [],
    ]);
    $suggestion = (new DossierRecord)->forceFill([
        'id' => 5,
        'value' => ['text' => 'De muur is te donker zichtbaar.'],
    ]);

    $draft = app(ContextualCustomerTaskBuilder::class)->forPhotoSuggestion($subject, $suggestion);

    expect($draft)->toMatchArray([
        'type' => FollowUpItemType::Photo->value,
        'decision_area_key' => 'capacity',
        'dossier_subject_id' => 21,
    ])
        ->and($draft['prompt'])->toContain('Slaapkamer')
        ->and($draft['prompt'])->toContain('te donker');
});
