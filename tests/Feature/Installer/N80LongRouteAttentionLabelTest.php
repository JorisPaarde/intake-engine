<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\RefreshStoredIntakeReport;
use App\Domains\Intake\Services\SyncRouteAttentionPoints;
use App\Enums\AircoConnectionStatus;
use App\Enums\AircoConnectionType;
use App\Enums\AircoPlacementType;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Queue::fake();
    $this->seed(IntakeTemplateSeeder::class);
    $this->user = User::factory()->create();
    $this->intake = app(CreateIntake::class)->handle($this->user, [
        'template_key' => 'airco', 'customer_name' => 'Drie ruimtes',
        'customer_email' => 'n80@example.com', 'address_line' => 'Testlaan 80',
        'address_postal_code' => '1000AA', 'address_house_number' => 80, 'address_city' => 'Amsterdam',
    ]);
});

test('N80 multi-room route attention label exceeds 255 chars and the column stores it', function () {
    $survey = app(AircoSurveyService::class);
    $outside = $survey->createPlacement($this->intake, $this->user, ['type' => AircoPlacementType::OutdoorUnit, 'label' => 'Buitenunit achtergevel']);
    $indoor = [];
    foreach (['Woonkamer', 'Slaapkamer 1', 'Slaapkamer 2'] as $name) {
        $room = $survey->createRoom($this->intake, $this->user, ['name' => $name]);
        $indoor[] = $survey->createPlacement($this->intake, $this->user, ['airco_room_id' => $room->id, 'type' => AircoPlacementType::IndoorUnit, 'label' => "Binnenunit {$name}"]);
    }
    $option = $survey->createInstallationOption($this->intake, $this->user, [
        'label' => 'Multisplit drie ruimtes', 'configuration_type' => 'multi_split',
        'placement_ids' => [...array_map(fn ($p) => $p->id, $indoor), $outside->id],
        'refrigerant_links' => array_map(fn ($p) => ['from_placement_id' => $p->id, 'to_placement_id' => $outside->id], $indoor),
    ]);
    foreach ($indoor as $p) {
        $survey->createConnection($this->intake, $this->user, $option, [
            'type' => AircoConnectionType::Condensate, 'label' => "Condensafvoer {$p->label}",
            'from_placement_id' => $p->id, 'status' => AircoConnectionStatus::Proposed,
        ]);
    }

    app(SyncRouteAttentionPoints::class)->handle($this->intake);

    $label = $this->intake->attentionPoints()->where('code', 'drillings_open')->firstOrFail()->label;
    expect(mb_strlen($label))->toBeGreaterThan(255)
        ->and(Schema::getColumnType('intake_attention_points', 'label'))->toBe('text');
});

test('N80 a failing route sync after a save is reported, not thrown', function () {
    app()->instance(SyncRouteAttentionPoints::class, new class extends SyncRouteAttentionPoints
    {
        public function handle(Intake $intake): void
        {
            throw new RuntimeException('sync kapot');
        }
    });

    expect(fn () => app(RefreshStoredIntakeReport::class)->handle($this->intake))->not->toThrow(RuntimeException::class);
});
