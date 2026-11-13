<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\IntakeStepBuilder;
use App\Domains\Intake\Support\CustomerAnswerBlocks;
use App\Domains\Intake\Support\CustomerCompletionSummary;
use App\Domains\Intake\Support\LandlordPermissionLabels;
use App\Domains\Intake\Support\SharedRoomAnswers;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function bl169UxReviewIntake(array $overrides = []): Intake
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'ux-review-bl169@example.com',
        'customer_name' => 'UX Review BL169',
        'address_line' => 'Bernadottelaan 273',
        'address_postal_code' => '2037GR',
        'address_house_number' => 273,
        'address_city' => 'Haarlem',
    ], $overrides));
}

test('airco v33: verhuurderstekst, geen building-subtekst, differs-label', function () {
    $intake = bl169UxReviewIntake();
    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    expect($version->version)->toBe(33);

    $building = $version->sections->firstWhere('key', 'building');
    expect($building?->description)->toBeNull();

    $landlord = $version->sections->flatMap(fn ($s) => $s->questions)->firstWhere('key', 'landlord_permission');
    expect($landlord?->label)->toBe(LandlordPermissionLabels::QUESTION_LABEL)
        ->and($landlord?->help_text)->toBe(LandlordPermissionLabels::HELP_TEXT)
        ->and($landlord?->options->pluck('label', 'value')->all())->toMatchArray(LandlordPermissionLabels::valueLabels());

    $floor = $version->sections->flatMap(fn ($s) => $s->questions)->firstWhere('key', 'floor_level');
    expect($floor?->meta['share_differs_label'] ?? null)->toBe(SharedRoomAnswers::DIFFERS_LABEL)
        ->and(SharedRoomAnswers::DIFFERS_LABEL)->toBe('Verschilt dit per ruimte?');
});

test('oude landlord yes/no/not_asked mappen naar nieuwe weergavelabels in dashboard en bedankt', function () {
    $intake = bl169UxReviewIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'ownership', null, ['value' => 'rented']);
    $save->handle($intake, 'landlord_permission', null, ['value' => 'yes']);

    $blocks = CustomerAnswerBlocks::forIntake($intake->fresh(['answers', 'templateVersion.sections.questions.options']) ?? $intake);
    $flat = collect($blocks)->flatMap(fn (array $block) => $block['items']);
    $landlordItem = $flat->first(
        static fn (array $item): bool => $item['label'] === LandlordPermissionLabels::QUESTION_LABEL,
    );
    expect($landlordItem['value'] ?? null)->toBe('Ja, die vindt het goed');

    $save->handle($intake, 'landlord_permission', null, ['value' => 'no']);
    $summary = CustomerCompletionSummary::forIntake(
        $intake->fresh(['answers', 'uploads', 'aircoRooms', 'templateVersion.sections.questions.options']) ?? $intake,
    );
    expect(collect($summary['highlights'])->firstWhere('label', LandlordPermissionLabels::SUMMARY_LABEL)['value'] ?? null)
        ->toBe('Nee, die is tegen');
});

test('koop/huur toont geen Gegevens over het gebouw subtekst', function () {
    $intake = bl169UxReviewIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh() ?? $intake, $version);
    $ownership = collect($steps)->firstWhere('question_key', 'ownership');
    expect($ownership)->not->toBeNull()
        ->and($ownership['description'] ?? null)->toBeNull();

    $index = collect($steps)->search(fn (array $s): bool => ($s['question_key'] ?? null) === 'ownership');
    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', $index)
        ->set('activeStepKey', $steps[$index]['key'])
        ->assertDontSee('Gegevens over het gebouw');
});

test('Opgeslagen staat in de voortgangsregel en differs is een gewone link', function () {
    $intake = bl169UxReviewIntake();
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 2]);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    $save->handle($intake, 'room_type', 'room-2', ['value' => 'bedroom']);
    app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

    $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
    $steps = app(IntakeStepBuilder::class)->build($intake->fresh() ?? $intake, $version);
    $shared = collect($steps)->first(
        static fn (array $step): bool => ($step['question_key'] ?? null) === 'floor_level'
            && ($step['shared_scope'] ?? false) === true,
    );
    expect($shared)->not->toBeNull();

    $html = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('activeStepKey', $shared['key'])
        ->set('saveMessage', 'Opgeslagen')
        ->assertSeeHtml('data-testid="save-message"')
        ->assertSeeHtml('data-testid="shared-differs"')
        ->assertSee('Verschilt dit per ruimte?', false)
        ->assertDontSee('border-dashed', false)
        ->html();

    expect($html)->toContain('data-testid="save-message"')
        ->and($html)->toContain('Opgeslagen')
        ->and($html)->toContain('data-testid="progress-percent"');
});
