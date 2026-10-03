<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake('local');
});

function uxReviewIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'ux-review@example.com',
        'customer_name' => 'UX Tester',
    ], $overrides));
}

test('thank-you toont geen aandachtspunten, keys of PDF-demo-tekst', function () {
    $intake = uxReviewIntake(['is_demo' => true]);

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::Ai,
        'code' => 'outdoor_location',
        'label' => 'outdoor_location outdoor_mount_type (wall) room_type (room-2) office',
        'status' => AttentionPointStatus::Proposed,
    ]);

    // Bedanktscherm verschijnt in dezelfde Livewire-sessie na afronden (heropenen = 410).
    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('completed', true)
        ->assertSeeHtml('data-testid="customer-thank-you"')
        ->assertSeeHtml('data-testid="customer-complete-note"')
        ->assertSee('Jouw deel is compleet. Open technische restpunten bekijkt je installateur apart.')
        ->assertDontSee('Voorgestelde aandachtspunten')
        ->assertDontSee('outdoor_mount_type (wall)')
        ->assertDontSee('room_type (room-2)')
        ->assertDontSee('een PDF gaat alleen als je die aanvraagt');
});

test('klantviews lekken geen ruwe question keys of Engelse enum-waarden', function () {
    $intake = uxReviewIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_location', null, ['value' => 'garden'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'outdoor_mount_type', null, ['value' => 'wall'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'sun_exposure', 'room-1', ['value' => 'medium'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'glass_amount', 'room-1', ['value' => 'average'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'preferred_indoor_location',
        'room-1',
        ['text' => 'boven de bank aan de buitenmuur'],
        PrefillSources::AI_TEXT,
    );
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh() ?? $intake, $version);
    $summary = collect($steps)->firstWhere('kind', 'known_summary');

    expect($summary)->not->toBeNull();
    $htmlValues = collect($summary['known_items'] ?? [])->pluck('display_value')->implode(' | ');
    $htmlLabels = collect($summary['known_items'] ?? [])->pluck('label')->implode(' | ');

    expect($htmlValues)
        ->not->toContain('outdoor_location')
        ->not->toContain('wall')
        ->not->toContain('bedroom')
        ->not->toContain('medium')
        ->not->toContain('average')
        ->not->toContain('garden')
        ->and($htmlLabels)->not->toContain('outdoor_mount_type')
        ->and($htmlValues)->toContain('boven de bank aan de buitenmuur')
        ->and($htmlValues)->toContain('Slaapkamer')
        ->and($htmlValues)->toContain('Tuin / achtererf')
        ->and($htmlValues)->toContain('Aan de gevel of muurbeugel');

    $summaryIndex = collect($steps)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $summaryIndex)
        ->set('activeStepKey', $steps[$summaryIndex]['key'])
        ->assertSee('boven de bank aan de buitenmuur')
        ->assertSee('Slaapkamer')
        ->assertSee('Tuin / achtererf')
        ->assertSee('Aan de gevel of muurbeugel')
        ->assertSee('Klopt, verder')
        // Zichtbare lekpatronen (niet de interne wire:click-keys).
        ->assertDontSee('(wall)')
        ->assertDontSee('room-2')
        ->assertDontSee('living_room')
        ->assertDontSee('no_preference');
});

test('voortgang gebruikt één stappenmaat zonder taken-teller', function () {
    $intake = uxReviewIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    $steps = $component->viewData('steps');
    $total = count($steps);

    expect($component->viewData('progressTotal'))->toBe($total)
        ->and($component->viewData('stepDisplayTotal'))->toBe($total)
        ->and($component->html())->not->toContain('taken afgerond');

    $target = min(5, max(1, $total - 2));
    $component->set('stepIndex', $target)->set('activeStepKey', $steps[$target]['key']);

    expect($component->viewData('stepDisplayNumber'))->toBe($target + 1)
        ->and($component->viewData('progressAnswered'))->toBe($target)
        ->and($component->viewData('progressTotal'))->toBe(count($component->viewData('steps')));
});

test('known-summary heeft één primaire CTA en toont binnenunitplek', function () {
    $intake = uxReviewIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'cooling_heating', null, ['value' => 'cooling'], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1], PrefillSources::AI_TEXT);
    app(SaveIntakeAnswer::class)->handle(
        $intake,
        'preferred_indoor_location',
        'room-1',
        ['text' => 'boven de bank aan de buitenmuur'],
        PrefillSources::AI_TEXT,
    );
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    $summaryIndex = collect($steps)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'known_summary');

    $html = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $summaryIndex)
        ->set('activeStepKey', $steps[$summaryIndex]['key'])
        ->assertSee('boven de bank aan de buitenmuur')
        ->assertSee('Klopt, verder')
        ->html();

    // Sticky CTA once; no duplicate in-body primary button.
    expect(substr_count($html, 'Klopt, verder'))->toBe(1)
        ->and(substr_count($html, '>Volgende<'))->toBe(0);
});

test('merk planning en opmerkingen staan op één scherm', function () {
    $intake = uxReviewIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );

    $closing = collect($steps)->firstWhere('kind', 'closing_wishes');
    expect($closing)->not->toBeNull()
        ->and($closing['bundle_question_keys'] ?? [])->toContain('brand_preference')
        ->and($closing['bundle_question_keys'])->toContain('desired_planning')
        ->and($closing['bundle_question_keys'])->toContain('additional_comments');

    $keys = array_column($steps, 'question_key');
    expect($keys)->not->toContain('brand_preference')
        ->and($keys)->not->toContain('desired_planning')
        ->and($keys)->not->toContain('additional_comments')
        ->and($keys)->toContain('_closing_wishes');

    $index = collect($steps)->search(fn (array $s): bool => ($s['kind'] ?? '') === 'closing_wishes');

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', $steps[$index]['key'])
        ->assertSee('Merk, planning en opmerkingen')
        ->assertSee('Heb je voorkeur voor een merk?')
        ->assertSee('Wanneer zou je de installatie het liefst laten uitvoeren?')
        ->assertSee('Aanvullende opmerkingen?');
});

test('extra ruimte-overzicht noemt ontbrekende wand deur of stopcontact', function () {
    $intake = uxReviewIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(SaveIntakeAnswer::class)->handle($intake, 'room_outlet_status', 'room-1', ['value' => 'needs_photo'], PrefillSources::AI_PHOTO);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    $extra = collect($steps)->firstWhere('question_key', 'indoor_unit_position_photo');

    expect($extra)->not->toBeNull()
        ->and($extra['help_text'])->toContain('stopcontact')
        ->and($extra['help_text'])->toContain('wand')
        ->and($extra['help_text'])->toContain('deur')
        ->and($extra['help_text'])->toContain('geen plek voor een binnenunit');

    $index = collect($steps)->search(fn (array $s): bool => $s['question_key'] === 'indoor_unit_position_photo');

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', $steps[$index]['key'])
        ->assertSee('geen plek voor een binnenunit')
        ->assertSee('stopcontact');
});

test('goedgekeurde foto toont geen oranje waarschuwingsbox', function () {
    $intake = uxReviewIntake();
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $file = UploadedFile::fake()->image('ok-room.jpg', 1200, 900);
    $path = $file->store('intakes/'.$intake->id, 'local');

    IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'room_photos',
        'section_instance_key' => 'room-1',
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'ok-room.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1200,
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Room)->toArray(),
    ]);

    $steps = app(IntakeStepBuilder::class)->build(
        $intake->fresh() ?? $intake,
        $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail(),
    );
    $index = collect($steps)->search(fn (array $s): bool => $s['question_key'] === 'room_photos');

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', $steps[$index]['key'])
        ->assertDontSeeHtml('data-testid="photo-mismatch-panel"')
        ->assertDontSeeHtml('data-testid="photo-quality-hint"')
        ->assertSeeHtml('data-testid="photo-receipt-status"');
});
