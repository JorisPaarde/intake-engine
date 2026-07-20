<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeReview;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\IntakeMetricsService;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\ReviewDecision;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-07-20 12:00:00');
    $this->seed(IntakeTemplateSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('metrics service calculates the product funnel per intake and in aggregate', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $first = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Reviewed,
        'created_at' => Carbon::parse('2026-07-20 06:00:00'),
        'started_at' => Carbon::parse('2026-07-20 07:00:00'),
        'completed_at' => Carbon::parse('2026-07-20 07:30:00'),
        'reviewed_at' => Carbon::parse('2026-07-20 08:00:00'),
        'progress_percent' => 100,
    ]);
    createCustomerAnswers($first, 2);
    createMetricEvent($first, 'answer_saved', '2026-07-20 07:05:00');
    createMetricEvent($first, 'answer_saved', '2026-07-20 07:10:00');
    createMetricEvent($first, 'answer_saved', '2026-07-20 07:15:00');
    createMetricEvent($first, 'upload_stored', '2026-07-20 07:20:00');
    createMetricEvent($first, 'intake_completed', '2026-07-20 07:30:00');
    createMetricEvent($first, 'intake_reviewed', '2026-07-20 08:00:00', 'user');
    createReview($first, $user, true, '2026-07-20 08:00:00');

    IntakeFollowUpRound::query()->create([
        'intake_id' => $first->id,
        'requested_by' => $user->id,
        'round_number' => 1,
        'status' => FollowUpRoundStatus::Completed,
        'sent_at' => Carbon::parse('2026-07-20 07:40:00'),
        'completed_at' => Carbon::parse('2026-07-20 07:50:00'),
    ]);

    $second = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Reviewed,
        'created_at' => Carbon::parse('2026-07-20 07:00:00'),
        'started_at' => Carbon::parse('2026-07-20 07:30:00'),
        'completed_at' => Carbon::parse('2026-07-20 09:00:00'),
        'reviewed_at' => Carbon::parse('2026-07-20 10:00:00'),
        'progress_percent' => 100,
    ]);
    createCustomerAnswers($second, 4);
    createMetricEvent($second, 'follow_up_text_saved', '2026-07-20 08:30:00');
    createMetricEvent($second, 'intake_completed', '2026-07-20 09:00:00');
    createMetricEvent($second, 'intake_reviewed', '2026-07-20 10:00:00', 'user');
    createReview($second, $user, false, '2026-07-20 10:00:00');

    $dropout = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'created_at' => Carbon::parse('2026-07-20 08:00:00'),
        'started_at' => Carbon::parse('2026-07-20 09:00:00'),
        'completed_at' => null,
        'current_question_key' => 'request_reason',
        'progress_percent' => 10,
    ]);
    createCustomerAnswers($dropout, 1);

    Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'created_at' => Carbon::parse('2026-07-20 09:00:00'),
    ]);

    Intake::factory()->completed()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'is_demo' => true,
    ]);

    $metrics = app(IntakeMetricsService::class)->calculate(Carbon::parse('2026-06-20 00:00:00'));

    expect($metrics['summary'])
        ->created_count->toBe(4)
        ->started_count->toBe(3)
        ->completed_count->toBe(2)
        ->completion_percent->toBe(66.7)
        ->median_customer_duration_seconds->toBe(3600)
        ->median_customer_actions->toBe(5)
        ->follow_up_rounds->toBe(1)
        ->average_follow_up_rounds->toBe(0.3)
        ->reviewed_count->toBe(2)
        ->enough_information_count->toBe(1)
        ->enough_information_percent->toBe(50.0)
        ->median_decision_seconds->toBe(9000);

    $firstRow = collect($metrics['intakes'])->firstWhere('id', $first->id);

    expect($firstRow)
        ->customer_duration_seconds->toBe(1800)
        ->customer_actions->toBe(5)
        ->follow_up_rounds->toBe(1)
        ->enough_information->toBeTrue()
        ->decision_seconds->toBe(7200);

    expect($metrics['dropoffs'])->toBe([
        [
            'key' => 'request_reason',
            'label' => 'Wat is de reden van uw aanvraag?',
            'count' => 1,
        ],
    ]);
});

test('installer can view metrics and guests cannot', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('metrics'))
        ->assertOk()
        ->assertSee('Resultaten')
        ->assertSee('Mediane invultijd')
        ->assertSee('Direct genoeg informatie')
        ->assertSee('aria-current="page"', false)
        ->assertDontSee('access_token');

    auth()->logout();

    $this->get(route('metrics'))->assertRedirect(route('login'));
});

test('direct enough information uses the first review instead of the overwritten final review', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Reviewed,
        'created_at' => Carbon::parse('2026-07-20 06:00:00'),
        'started_at' => Carbon::parse('2026-07-20 06:10:00'),
        'completed_at' => Carbon::parse('2026-07-20 06:30:00'),
        'reviewed_at' => Carbon::parse('2026-07-20 08:00:00'),
    ]);

    createMetricEvent($intake, 'intake_reviewed', '2026-07-20 07:00:00', 'user', [
        'decision' => ReviewDecision::NeedMoreInfo->value,
        'enough_information' => false,
    ]);
    createMetricEvent($intake, 'intake_reviewed', '2026-07-20 08:00:00', 'user', [
        'decision' => ReviewDecision::PrepareQuote->value,
        'enough_information' => true,
    ]);
    createReview($intake, $user, true, '2026-07-20 08:00:00');

    $metrics = app(IntakeMetricsService::class)->calculate();
    $row = collect($metrics['intakes'])->firstWhere('id', $intake->id);

    expect($row)
        ->enough_information->toBeFalse()
        ->decision_seconds->toBe(3600)
        ->and($metrics['summary'])
        ->reviewed_count->toBe(1)
        ->enough_information_count->toBe(0)
        ->enough_information_percent->toBe(0.0);
});

test('answer saves emit a privacy safe customer action event', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
    ]);

    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'request_reason',
        null,
        ['text' => 'Dit is vertrouwelijke klanttekst'],
    );

    $event = IntakeActivityEvent::query()
        ->whereBelongsTo($intake)
        ->where('event', 'answer_saved')
        ->sole();

    expect($event->actor_type)->toBe('customer')
        ->and($event->properties)->toBe([
            'question_key' => 'request_reason',
            'section_instance_key' => null,
        ])
        ->and(json_encode($event->properties))->not->toContain('vertrouwelijke klanttekst');
});

function createCustomerAnswers(Intake $intake, int $count): void
{
    $questionKeys = ['request_reason', 'cooling_heating', 'indoor_unit_count', 'brand_preference'];

    foreach (array_slice($questionKeys, 0, $count) as $index => $questionKey) {
        IntakeAnswer::query()->create([
            'intake_id' => $intake->id,
            'question_key' => $questionKey,
            'section_instance_key' => null,
            'value' => ['text' => 'waarde-'.$index],
            'prefill_source' => null,
            'answered_at' => now(),
        ]);
    }
}

/** @param array<string, mixed> $properties */
function createMetricEvent(
    Intake $intake,
    string $event,
    string $at,
    string $actorType = 'customer',
    array $properties = [],
): void {
    IntakeActivityEvent::query()->create([
        'intake_id' => $intake->id,
        'actor_type' => $actorType,
        'actor_id' => null,
        'event' => $event,
        'properties' => $properties,
        'created_at' => Carbon::parse($at),
    ]);
}

function createReview(Intake $intake, User $reviewer, bool $enoughInformation, string $reviewedAt): void
{
    IntakeReview::query()->create([
        'intake_id' => $intake->id,
        'reviewer_id' => $reviewer->id,
        'decision' => $enoughInformation ? ReviewDecision::PrepareQuote : ReviewDecision::NeedMoreInfo,
        'site_visit_needed' => false,
        'enough_information' => $enoughInformation,
        'summary' => null,
        'reviewed_at' => Carbon::parse($reviewedAt),
    ]);
}
