<?php

declare(strict_types=1);

use App\Domains\Intake\Models\AircoConnection;
use App\Domains\Intake\Support\InstallerDisplayLabels;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;

function n99Connection(AircoConnectionType $type, AircoConnectionStatus $status): AircoConnection
{
    $connection = (new AircoConnection)->forceFill([
        'type' => $type,
        'status' => $status,
        'from_placement_id' => null,
    ]);
    $connection->setRelation('fromPlacement', null);

    return $connection;
}

test('N99: goedgekeurde stroomroute zonder vastgelegd beginpunt start bij de stroomaansluiting', function () {
    expect(InstallerDisplayLabels::connectionStart(n99Connection(AircoConnectionType::Power, AircoConnectionStatus::Approved)))
        ->toBe('Stroomaansluiting');
});

test('N99: niet-goedgekeurde of andere route houdt Beginpunt open', function () {
    expect(InstallerDisplayLabels::connectionStart(n99Connection(AircoConnectionType::Power, AircoConnectionStatus::NeedsEvidence)))
        ->toBe('Beginpunt open')
        ->and(InstallerDisplayLabels::connectionStart(n99Connection(AircoConnectionType::Refrigerant, AircoConnectionStatus::Approved)))
        ->toBe('Beginpunt open');
});
