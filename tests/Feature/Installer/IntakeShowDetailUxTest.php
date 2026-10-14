<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SuggestAttentionPoints;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\SubmitIntakeReview;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Services\WorkspacePrimaryActionResolver;
use App\Enums\AircoConfigurationType;
use App\Enums\AircoPlacementType;
use App\Enums\AiRunStatus;
use App\Enums\AiRunType;
use App\Enums\ContributionMode;
use App\Enums\ContributionTaskStatus;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Enums\ReviewDecision;
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
        ->assertSee('Aandachtspunten')
        ->assertSee('AI staat uit in deze omgeving')
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

    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'no'], 'installer');
    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, ['bool' => false], 'installer');

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
    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'no'], 'installer');
    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, ['bool' => false], 'installer');
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
        ->assertSee('Ververs de status over een moment')
        ->assertDontSee('De pagina ververst automatisch')
        ->assertSee('data-lazy-report', false)
        ->getContent();

    expect($html)->toMatch('/data-src="'.preg_quote(route('intakes.report', $intake), '/').'"/')
        ->and(preg_match('/<iframe[^>]*\ssrc="/', $html))->toBe(0);
});

test('N27: detail page labels werkwijze and leads next step with saved prepare-quote decision', function () {
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user, [
        'workflow_mode' => ContributionMode::Hybrid,
    ]);
    app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5,
        'width_m' => 4,
        'height_m' => 2.5,
    ]);
    $intake->update([
        'status' => IntakeStatus::Completed,
        'completed_at' => now(),
    ]);

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $user, [
        'decision' => ReviewDecision::PrepareQuote,
        'summary' => 'Genoeg voor een eerste offerte.',
        'enough_information' => true,
    ]);

    $response = $this->actingAs($user)->get(route('intakes.show', $intake->fresh()));

    $response->assertOk()
        ->assertSee('Werkwijze: Samen met de klant', false)
        ->assertSee('data-testid="workflow-mode-label"', false)
        ->assertSee('Je koos ‘Offerte voorbereiden’', false)
        ->assertSee('onderdelen open', false)
        ->assertSee('Klaar voor offerte:', false)
        ->assertSee('onderdelen', false)
        ->assertSee('data-testid="detail-next-action-summary"', false);

    $html = $response->getContent();
    expect($html)->toContain('data-testid="intake-status-badge"')
        ->and($html)->toContain('Beoordeeld')
        ->and($html)->toContain('Werkwijze: Samen met de klant')
        ->and($html)->toContain('data-testid="quote-readiness-count"')
        ->and($html)->toContain('Klaar voor offerte:')
        ->and($html)->not->toMatch('/<span class="text-gray-500">Samen met de klant<\/span>/');
});

test('N27: prepare-quote with open points shows blocked panel copy', function () {
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user, [
        'workflow_mode' => ContributionMode::Hybrid,
    ]);
    $survey = app(AircoSurveyService::class);
    $room = $survey->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5,
        'width_m' => 4,
        'height_m' => 2.5,
    ]);
    $inside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::IndoorUnit,
        'label' => 'Binnenunit woonkamer',
        'airco_room_id' => $room->id,
    ]);
    $outside = $survey->createPlacement($intake, $user, [
        'type' => AircoPlacementType::OutdoorUnit,
        'label' => 'Buitenunit',
    ]);
    $survey->createInstallationOption($intake, $user, [
        'label' => 'Single-split',
        'configuration_type' => AircoConfigurationType::SingleSplit,
        'placement_ids' => [$inside->id, $outside->id],
    ]);
    $intake->update([
        'status' => IntakeStatus::Completed,
        'completed_at' => now(),
    ]);

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $user, [
        'decision' => ReviewDecision::PrepareQuote,
        'summary' => 'Genoeg voor een eerste offerte.',
        'enough_information' => true,
    ]);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake->fresh()))
        ->assertOk()
        ->assertSee('Je koos ‘Offerte voorbereiden’, maar er staan nog punten open', false)
        ->assertSee('Je kunt het voorstel pas goedkeuren als deze punten zijn opgelost. Een locatiebezoek blijft mogelijk. Losse AI-voorstellen kun je hieronder per stuk accepteren of verwijderen.', false);
});

test('N27: after follow-up round completes, stale review decision no longer shows Je koos', function () {
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user, [
        'workflow_mode' => ContributionMode::Hybrid,
    ]);
    app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
        'length_m' => 5,
        'width_m' => 4,
        'height_m' => 2.5,
    ]);
    $intake->update([
        'status' => IntakeStatus::Completed,
        'completed_at' => now(),
    ]);

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $user, [
        'decision' => ReviewDecision::PrepareQuote,
        'summary' => 'Genoeg voor een eerste offerte.',
        'enough_information' => true,
    ]);

    expect($intake->fresh()->reviewed_at)->not->toBeNull()
        ->and($this->actingAs($user)->get(route('intakes.show', $intake->fresh()))->getContent())
        ->toContain('Je koos ‘Offerte voorbereiden’');

    // CompleteFollowUpRound / CompleteInstallerSurvey clear reviewed_at; review row may remain.
    $intake->fresh()->update([
        'reviewed_at' => null,
        'status' => IntakeStatus::Completed,
    ]);

    $html = $this->actingAs($user)
        ->get(route('intakes.show', $intake->fresh(['review'])))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Je koos ‘Offerte voorbereiden’')
        ->and($html)->not->toContain('Je koos ‘Offerte voorbereiden’, maar er staan nog punten open');
});

test('N27: pending dossier synthesis without placements shows neutral updating summary', function () {
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user);
    app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living_room',
    ]);

    AiRun::query()->create([
        'intake_id' => $intake->id,
        'type' => AiRunType::DossierSynthesis,
        'provider' => 'fake',
        'model' => 'fake',
        'prompt_version' => 'dossier-synthesis-v11',
        'input_hash' => 'n27-pending',
        'status' => AiRunStatus::Pending,
        'started_at' => now(),
    ]);

    $intake = $intake->fresh(['aircoRooms', 'aircoPlacements', 'aircoInstallationOptions', 'review']);
    expect($intake->aircoPlacements)->toBeEmpty();

    $action = app(WorkspacePrimaryActionResolver::class)->resolve(
        $intake,
        null,
        false,
        false,
        collect(),
        collect(),
    );

    expect($action['summary'])->toBe('Het AI-voorstel wordt nog bijgewerkt. Vernieuw de pagina over een minuut.')
        ->and($action['label'])->toBe('');

    $html = $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Het AI-voorstel wordt nog bijgewerkt. Vernieuw de pagina over een minuut.', false)
        ->assertDontSee('Even geduld', false)
        ->assertSee('data-testid="customer-link-inactive"', false)
        ->assertDontSee('data-testid="customer-link-inactive-cta"', false)
        ->getContent();

    expect($html)->not->toContain('data-testid="detail-next-action-cta"')
        ->and($html)->not->toContain('data-testid="customer-link-inactive-cta"');
});

test('review form does not check voldoende informatie by default and hides duplicate locatiebezoek', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user);
    $intake->update([
        'status' => IntakeStatus::Completed,
        'completed_at' => now(),
    ]);

    $html = $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('data-testid="review-form"', false)
        ->assertSee('data-testid="review-enough-information"', false)
        ->getContent();

    expect($html)->toMatch('/data-testid="review-enough-information"[\s\S]*?<input[^>]*name="enough_information"[^>]*>/')
        ->and($html)->not->toMatch('/name="enough_information"[^>]*checked/')
        ->and($html)->toContain("decision === 'site_visit_needed'")
        ->and($html)->toContain('data-testid="review-site-visit-needed"');
});

test('review summary omits separate locatiebezoek row when decision is site visit needed', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user);
    $intake->update([
        'status' => IntakeStatus::Completed,
        'completed_at' => now(),
    ]);

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $user, [
        'decision' => ReviewDecision::SiteVisitNeeded,
        'site_visit_needed' => true,
        'enough_information' => false,
        'summary' => 'Op locatie bekijken.',
    ]);

    $html = $this->actingAs($user)
        ->get(route('intakes.show', $intake->fresh()))
        ->assertOk()
        ->assertSee('data-testid="review-summary"', false)
        ->assertSee('Locatiebezoek nodig', false)
        ->getContent();

    // Beslissing toont het label; geen aparte Ja/Nee-rij in de samenvatting.
    expect($html)->toMatch('/data-testid="review-summary"[\s\S]*?<dd class="text-gray-900">Locatiebezoek nodig<\/dd>/')
        ->and($html)->not->toMatch('/data-testid="review-summary"[\s\S]*?<dt class="text-gray-500">Locatiebezoek nodig<\/dt>/');
});

test('after follow-up clears reviewed_at, show page marks previous review as stale', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user, [
        'workflow_mode' => ContributionMode::Hybrid,
    ]);
    $intake->update([
        'status' => IntakeStatus::Completed,
        'completed_at' => now(),
    ]);

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $user, [
        'decision' => ReviewDecision::PrepareQuote,
        'summary' => 'Eerste beoordeling.',
        'enough_information' => true,
    ]);

    $intake->fresh()->update([
        'reviewed_at' => null,
        'status' => IntakeStatus::Completed,
    ]);

    $html = $this->actingAs($user)
        ->get(route('intakes.show', $intake->fresh(['review', 'followUpRounds'])))
        ->assertOk()
        ->assertSee('data-testid="review-stale-before-round"', false)
        ->assertSee('Vorige beslissing', false)
        ->getContent();

    expect($html)->toContain('beoordeel opnieuw')
        ->and($html)->not->toContain('Je koos ‘Offerte voorbereiden’');
});

test('review form shows follow-up round limit up front', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user);
    $intake->update([
        'status' => IntakeStatus::Completed,
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('data-testid="review-follow-up-round-limit"', false)
        ->assertSee('Maximaal 3 aanvullende rondes', false)
        ->assertSee('0 van 3 gebruikt', false);
});

test('review validation errors are visible above details and open the review block', function (bool $exhausted) {
    $this->withoutVite();
    $user = User::factory()->create();
    $intake = makeDetailUxIntake($user);
    $intake->update(['status' => IntakeStatus::Completed, 'completed_at' => now()]);

    if ($exhausted) {
        foreach (range(1, 3) as $number) {
            $intake->followUpRounds()->create([
                'requested_by' => $user->id,
                'round_number' => $number,
                'status' => FollowUpRoundStatus::Completed,
                'sent_at' => now(),
                'completed_at' => now(),
            ]);
        }
    }

    $payload = $exhausted ? [
        'decision' => ReviewDecision::NeedMoreInfo->value,
        'summary' => 'Bewaar deze notitie.',
        'follow_up_items' => [['type' => 'text', 'prompt' => 'Welke gevel?']],
    ] : [];
    $key = $exhausted ? 'follow_up_items' : 'decision';
    $message = $exhausted
        ? 'Maximaal 3 aanvullende rondes toegestaan. Plan nu gericht contact of een locatiebezoek.'
        : 'Beoordeling is verplicht.';

    $this->actingAs($user)->from(route('intakes.show', $intake))
        ->post(route('intakes.review', $intake), $payload)
        ->assertRedirect(route('intakes.show', $intake))
        ->assertSessionHasErrors($key);

    // Preserve the browser session across the validation redirect.
    $this->withCookie(config('session.cookie'), session()->getId());
    $html = $this->get(route('intakes.show', $intake))->assertOk()->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xpath = new DOMXPath($dom);
    $summary = $xpath->query('//*[@data-testid="validation-error-summary"]')->item(0);
    expect($summary)->not->toBeNull()
        ->and($summary->textContent)->toContain($message)
        ->and($xpath->query('//*[@data-testid="validation-error-summary"]/ancestor::details')->length)->toBe(0)
        ->and($xpath->query('//*[@data-testid="review-details"][@open]')->length)->toBe(1);

    if ($exhausted) {
        expect($xpath->query('//select[@id="decision"]/option[@value="need_more_info"][@disabled]')->length)->toBe(1)
            ->and($xpath->query('//*[@data-testid="review-follow-up-round-exhausted"]/ancestor::*[@x-show]')->length)->toBe(0)
            ->and($html)->toContain('Bewaar deze notitie.')
            ->and($intake->followUpRounds()->count())->toBe(3);
    }
})->with([false, true]);
