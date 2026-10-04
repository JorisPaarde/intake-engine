<?php

declare(strict_types=1);

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\DossierDecisionArea;
use App\Domains\Intake\Models\DossierRecord;
use App\Domains\Intake\Models\DossierSubject;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\ContextualCustomerTaskBuilder;
use App\Domains\Intake\Support\CustomerFacingTaskText;
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

test('power mismatch blocker becomes customer retake prompt, not installer diagnosis', function () {
    $intake = builderIntakeWithRooms();
    $intake->setRelation('aircoInstallationOptions', collect());
    $area = new DossierDecisionArea([
        'key' => 'power',
        'label' => 'Stroomtoevoer',
        'status' => DecisionAreaStatus::Blocked,
        'blocker' => 'Ontvangen foto lijkt een buitenunit, geen meterkast — handmatig controleren',
        'next_action' => DossierNextAction::RequestContribution,
    ]);

    $draft = app(ContextualCustomerTaskBuilder::class)->forDecisionArea($intake, $area);

    expect($draft)->not->toBeNull()
        ->and($draft['type'])->toBe(FollowUpItemType::Photo->value)
        ->and($draft['decision_area_key'])->toBe('power')
        ->and($draft['prompt'])->toBe('Maak een nieuwe, duidelijke foto van je meterkast')
        ->and($draft['prompt'])->not->toContain('handmatig controleren')
        ->and($draft['prompt'])->not->toContain('Ontvangen foto lijkt');
});

test('power meterkast blocker uses neutral customer text without phase choice', function () {
    $intake = builderIntakeWithRooms();
    $intake->setRelation('aircoInstallationOptions', collect());
    $area = new DossierDecisionArea([
        'key' => 'power',
        'label' => 'Stroomtoevoer',
        'status' => DecisionAreaStatus::Blocked,
        'blocker' => 'Voeg een duidelijke meterkastfoto toe; de groepenkast moet volledig leesbaar zijn.',
        'next_action' => DossierNextAction::RequestContribution,
    ]);

    $draft = app(ContextualCustomerTaskBuilder::class)->forDecisionArea($intake, $area);

    expect($draft)->not->toBeNull()
        ->and($draft['prompt'])->toBe(CustomerFacingTaskText::fuseboxPhotoPrompt())
        ->and($draft['prompt'])->toContain('groepenkast volledig leesbaar')
        ->and($draft['prompt'])->toContain('installateur beoordeelt de aansluiting')
        ->and($draft['prompt'])->not->toContain('1- of 3-fase')
        ->and($draft['prompt'])->not->toContain('Daaruit volgt');
});

test('photo suggestion does not double Maak-een prefix', function () {
    $subject = (new DossierSubject)->forceFill([
        'id' => 22,
        'type' => 'airco_room',
        'label' => 'Meterkast',
        'meta' => [],
    ]);
    $suggestion = (new DossierRecord)->forceFill([
        'id' => 6,
        'value' => ['text' => 'Maak een nieuwe, duidelijke foto van je meterkast'],
    ]);

    $draft = app(ContextualCustomerTaskBuilder::class)->forPhotoSuggestion($subject, $suggestion);

    expect($draft['prompt'])->toBe('Maak een nieuwe, duidelijke foto van je meterkast')
        ->and(substr_count(mb_strtolower($draft['prompt']), 'maak een nieuwe'))->toBe(1);
});
