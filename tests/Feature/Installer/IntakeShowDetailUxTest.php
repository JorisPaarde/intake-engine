<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function makeDetailUxIntake(User $user, array $overrides = []): Intake
{
    return app(CreateIntake::class)->handle($user, array_merge([
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Detail UX Test',
        'customer_email' => 'detail-ux@example.com',
        'customer_phone' => '06 12345678',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ], $overrides));
}

test('detail page links open points to workspace anchors and contact fields are actionable', function () {
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user);
    app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
    ]);

    $response = $this->actingAs($user)->get(route('intakes.show', $intake));

    $response->assertOk()
        ->assertSee('Wat nu te doen')
        ->assertSee('Open punten')
        ->assertSee('Volgende open punt')
        ->assertSee('href="'.route('intakes.workspace', $intake).'#room-', false)
        ->assertSee('mailto:detail-ux@example.com', false)
        ->assertSee('tel:0612345678', false)
        ->assertSee('google.com/maps/search', false);
});

test('detail page shows ai disabled explanation instead of a dead empty ai section', function () {
    config(['ai.provider' => 'null']);
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertDontSee('AI-voorgestelde aandachtspunten')
        ->assertDontSee('Geen openstaande AI-voorstellen');
});

test('detail page offers manual ai attention trigger when ai is available but not yet run', function () {
    config(['ai.provider' => 'heuristic']);
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('AI-aandachtspunten voorstellen')
        ->assertDontSee('AI-voorgestelde aandachtspunten');
});

test('installer can trigger ai attention suggestions from the detail page', function () {
    config(['ai.provider' => 'heuristic']);
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
    ]);

    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'no']);
    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, ['bool' => false]);

    $this->actingAs($user)
        ->post(route('intakes.attention.suggest', $intake))
        ->assertRedirect(route('intakes.show', $intake));

    expect($intake->fresh()->attentionPoints()->aiProposed()->count())->toBeGreaterThan(0);
});

test('merged attention section keeps accept and dismiss actions for ai proposals', function () {
    config(['ai.provider' => 'heuristic']);
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create(['created_by' => $user->id, 'intake_template_version_id' => $version->id]);
    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'no']);
    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, ['bool' => false]);
    app(SuggestAttentionPoints::class)->handle($intake);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Aandachtspunten')
        ->assertSee('AI-voorstel · accepteren of verwijderen')
        ->assertSee('Accepteren')
        ->assertSee('Verwijderen')
        ->assertDontSee('AI-voorgestelde aandachtspunten');
});

test('photo usability warning exposes one click customer task on the detail page', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
    ]);
    $disk = (string) config('filesystems.media', 'local');
    Storage::disk($disk)->put('photos/meterkast.jpg', 'fake-image');
    $questionLabel = $version->sections
        ->flatMap(fn ($section) => $section->questions)
        ->firstWhere('key', 'fusebox_photo')
        ?->label ?? 'Meterkastfoto';

    IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'fusebox_photo',
        'section_instance_key' => null,
        'disk' => $disk,
        'path' => 'photos/meterkast.jpg',
        'original_filename' => 'meterkast.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'sort_order' => 1,
        'usability_verdict' => PhotoUsabilityVerdict::TooDark,
    ]);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Vraag betere foto');

    $this->actingAs($user)
        ->from(route('intakes.show', $intake))
        ->post(route('intakes.workspace.tasks.quick', $intake), [
            'type' => FollowUpItemType::Photo->value,
            'prompt' => 'Maak een scherpere, goed belichte foto: '.$questionLabel,
        ])
        ->assertRedirect(route('intakes.workspace', $intake));

    $intake->refresh();
    expect($intake->contributionTasks()->where('status', ContributionTaskStatus::Open)->count())->toBe(1);
});

test('detail page does not load report iframe by default', function () {
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user, ['status' => IntakeStatus::Completed]);
    GeneratedReport::query()->create([
        'intake_id' => $intake->id,
        'html' => '<html><body><h1>Rapport</h1></body></html>',
        'meta' => [],
        'generated_at' => now(),
    ]);

    $html = $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Rapport openen')
        ->assertSee('Status verversen')
        ->assertSee('data-lazy-report', false)
        ->getContent();

    expect($html)->toMatch('/data-src="'.preg_quote(route('intakes.report', $intake), '/').'"/')
        ->and(preg_match('/<iframe[^>]*\ssrc="/', $html))->toBe(0);
});
