<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\AircoRoom;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\CustomerAnswerBlocks;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function makeReview14R3Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Review Round3',
        'customer_email' => 'review14r3@example.com',
        'access_token' => str_repeat('t', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

test('R3-2: alleen plafondhoogte → Maten deels ingevuld in werkplek én overzicht', function () {
    $user = User::factory()->create();
    $intake = makeReview14R3Intake();
    $intake->update(['created_by' => $user->id, 'company_id' => $user->company_id]);

    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    app(DossierManager::class)->initialize($intake->fresh());

    $room = AircoRoom::query()->where('intake_id', $intake->id)->where('key', 'room-1')->firstOrFail();
    $room->update([
        'dimensions' => [
            'height_m' => 2.6,
            'dimensions_source' => 'customer',
        ],
    ]);

    expect(CustomerAnswerBlocks::roomDimensionsLabel($room->fresh()->dimensions))
        ->toBe('Maten deels ingevuld');

    $this->actingAs($user)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Maten deels ingevuld')
        ->assertDontSee('Maten nog leeg');

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Maten deels ingevuld')
        ->assertDontSee('Maten nog leeg');
});

test('R3-3: follow-up OutdoorLocation met subject_match=no blijft accepted-set-wint', function () {
    $accepted = [PhotoSubject::OutdoorLocation, PhotoSubject::OutdoorUnit];

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'detected_subject' => 'outdoor_unit',
            'subject_match' => 'no',
            'retake_instruction' => null,
        ],
        $accepted,
        null, // follow-up: geen questionKey
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_OK);
});

test('R3-3: derive-pad OutdoorLocation met subject_match=no blijft wrong_subject', function () {
    $accepted = PhotoSubject::acceptedSubjectsForPhotoQuestion('outdoor_location_photos', 'outdoor');

    $assessment = PhotoContentAssessment::fromModelOutput(
        PhotoSubject::OutdoorLocation,
        [
            'detected_subject' => 'outdoor_location',
            'subject_match' => 'no',
        ],
        $accepted,
        'outdoor_location_photos',
    );

    expect($assessment->status())->toBe(PhotoContentAssessment::STATUS_WRONG_SUBJECT);
});

test('R3-4: caption drop trailing ,0', function () {
    $caption = CustomerAnswerBlocks::roomDimensionsCaption([
        'length_m' => 3.5,
        'width_m' => 3.0,
        'dimensions_source' => 'customer',
    ]);

    expect($caption)->toBe('3,5 × 3 m (10,5 m²) · van klant')
        ->and($caption)->not->toContain('3,0');
});

test('R3-7: outdoor_accessibility verdwijnt uit forIntake als mount_type=ground', function () {
    $intake = makeReview14R3Intake();

    $version = $intake->fresh()->templateVersion()->with(['sections.questions.rules'])->firstOrFail();
    $questions = $version->sections->flatMap->questions->keyBy('key');
    $accessibility = $questions->get('outdoor_accessibility');
    $mountType = $questions->get('outdoor_mount_type');

    expect($accessibility)->not->toBeNull()
        ->and($mountType)->not->toBeNull();

    // Show-regel: outdoor_accessibility alleen zichtbaar als mount_type ≠ ground.
    $showRule = $accessibility->rules->first(
        static fn ($rule): bool => $rule->effect->value === 'show'
            && $rule->source_question_key === 'outdoor_mount_type',
    );
    expect($showRule)->not->toBeNull()
        ->and($showRule->operator->value)->toBe('not_equals');

    app(SaveIntakeAnswer::class)->handle($intake, 'ownership', null, ['value' => 'owned']);
    // Eerst bereikbaarheid beantwoorden terwijl die nog zichtbaar is (geen ground).
    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_accessibility', null, ['value' => 'easy']);

    $labelsVisible = [];
    foreach (CustomerAnswerBlocks::forIntake($intake->fresh()) as $block) {
        foreach ($block['items'] as $item) {
            $labelsVisible[] = $item['label'];
        }
    }
    expect($labelsVisible)->toContain($accessibility->label)
        ->and($labelsVisible)->toContain($questions->get('ownership')->label);

    // Daarna ground → VisibilityResolver verbergt outdoor_accessibility.
    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_mount_type', null, ['value' => 'ground']);

    $labelsHidden = [];
    foreach (CustomerAnswerBlocks::forIntake($intake->fresh()) as $block) {
        foreach ($block['items'] as $item) {
            $labelsHidden[] = $item['label'];
        }
    }

    expect($labelsHidden)->toContain($questions->get('ownership')->label)
        ->and($labelsHidden)->not->toContain($accessibility->label);
});

test('R3-6: items binnen een groep volgen question sort_order', function () {
    $intake = makeReview14R3Intake();

    // Bewust omgekeerde opslagvolgorde t.o.v. template sort_order.
    app(SaveIntakeAnswer::class)->handle($intake, 'drain_location', null, ['value' => 'outside_nearby']);
    app(SaveIntakeAnswer::class)->handle($intake, 'ownership', null, ['value' => 'owned']);

    $version = $intake->fresh()->templateVersion()->with(['sections.questions'])->firstOrFail();
    $questions = $version->sections->flatMap->questions->keyBy('key');
    $ownershipSort = (int) $questions->get('ownership')->sort_order;
    $drainSort = (int) $questions->get('drain_location')->sort_order;

    $blocks = CustomerAnswerBlocks::forIntake($intake->fresh());

    // Vind blokken die ownership of drain bevatten.
    $ownershipHeading = null;
    $drainHeading = null;
    $ownershipItemIndex = null;
    $drainItemIndex = null;
    $ownershipBlockItems = null;
    $drainBlockItems = null;

    foreach ($blocks as $block) {
        foreach ($block['items'] as $index => $item) {
            if ($item['label'] === $questions->get('ownership')->label) {
                $ownershipHeading = $block['heading'];
                $ownershipItemIndex = $index;
                $ownershipBlockItems = $block['items'];
            }
            if ($item['label'] === $questions->get('drain_location')->label) {
                $drainHeading = $block['heading'];
                $drainItemIndex = $index;
                $drainBlockItems = $block['items'];
            }
        }
    }

    expect($ownershipHeading)->not->toBeNull()
        ->and($drainHeading)->not->toBeNull();

    // Zelfde sectie: itemvolgorde volgt question sort_order, niet answer-creatietijd.
    if ($ownershipHeading === $drainHeading) {
        if ($ownershipSort < $drainSort) {
            expect($ownershipItemIndex)->toBeLessThan($drainItemIndex);
        } else {
            expect($drainItemIndex)->toBeLessThan($ownershipItemIndex);
        }
    } else {
        // Verschillende secties: elk blok intern gesorteerd op sort_order.
        $labelsInOwnership = array_column($ownershipBlockItems ?? [], 'label');
        $labelsInDrain = array_column($drainBlockItems ?? [], 'label');
        expect($labelsInOwnership)->toContain($questions->get('ownership')->label)
            ->and($labelsInDrain)->toContain($questions->get('drain_location')->label);
    }
});
