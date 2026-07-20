<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CompleteIntake;
use App\Domains\Intake\Actions\GenerateIntakePdf;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Actions\SubmitIntakeReview;
use App\Domains\Intake\Mail\CustomerFollowUpRequestMail;
use App\Domains\Intake\Mail\InstallerIntakeCompletedMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeTemplateVersion;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\PublishIntakeTemplateFromConfig;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Enums\QuestionType;
use App\Enums\ReviewDecision;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function makePhase5Intake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Rapport Klant',
        'customer_email' => 'rapport@example.com',
        'address_line' => 'Voorbeeldstraat 1',
        'address_city' => 'Utrecht',
    ]);
}

/**
 * Fill all currently missing required targets until the intake is complete.
 */
function fillIntakeUntilComplete(Intake $intake): void
{
    $save = app(SaveIntakeAnswer::class);
    $store = app(StoreIntakeUpload::class);
    $checker = app(CompletenessChecker::class);

    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);

    for ($attempt = 0; $attempt < 40; $attempt++) {
        $intake->refresh();
        $version = $intake->templateVersion()->with(['sections.questions.options', 'sections.questions.rules'])->firstOrFail();
        $check = $checker->check($intake, $version);

        if ($check['is_complete']) {
            return;
        }

        foreach ($check['missing'] as $item) {
            $question = findQuestionByKey($version, $item['question_key']);

            if ($question === null) {
                continue;
            }

            if ($question->type === QuestionType::Photo) {
                $store->handle(
                    $intake,
                    $item['question_key'],
                    $item['section_instance_key'],
                    UploadedFile::fake()->image($item['question_key'].'.jpg', 640, 480),
                );

                continue;
            }

            $save->handle(
                $intake,
                $item['question_key'],
                $item['section_instance_key'],
                sampleAnswerForQuestion($question),
            );
        }
    }

    throw new RuntimeException('Could not fill intake to completion within attempt budget.');
}

function findQuestionByKey(IntakeTemplateVersion $version, string $key): ?IntakeQuestion
{
    foreach ($version->sections as $section) {
        foreach ($section->questions as $question) {
            if ($question->key === $key) {
                return $question;
            }
        }
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function sampleAnswerForQuestion(IntakeQuestion $question): array
{
    return match ($question->type) {
        QuestionType::ShortText, QuestionType::LongText => ['text' => 'Testantwoord '.$question->key],
        QuestionType::Number => ['number' => 1],
        QuestionType::SingleChoice => ['value' => $question->options->first()?->value ?? 'yes'],
        QuestionType::MultiChoice => ['values' => [$question->options->first()?->value ?? 'a']],
        QuestionType::Boolean => ['bool' => true],
        QuestionType::Photo => ['upload_ids' => []],
    };
}

function darkFollowUpUpload(): UploadedFile
{
    $image = imagecreatetruecolor(800, 800);
    imagefill($image, 0, 0, imagecolorallocate($image, 8, 8, 8));
    ob_start();
    imagejpeg($image, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return UploadedFile::fake()->createWithContent('donkere-aanvulling.jpg', $bytes);
}

test('incomplete intake cannot be completed', function () {
    $intake = makePhase5Intake();

    expect(fn () => app(CompleteIntake::class)->handle($intake))
        ->toThrow(ValidationException::class);

    expect($intake->fresh()->status)->toBe(IntakeStatus::Sent);
});

test('complete intake stores snapshot report and attention points', function () {
    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);

    app(SaveIntakeAnswer::class)->handle($intake, 'free_group_known', null, ['value' => 'no']);
    app(SaveIntakeAnswer::class)->handle($intake, 'natural_fall_possible', null, ['bool' => false]);

    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    expect($completed->status)->toBe(IntakeStatus::Completed)
        ->and($completed->completed_at)->not->toBeNull()
        ->and($completed->completeness_snapshot['is_complete'])->toBeTrue()
        ->and($completed->report)->not->toBeNull()
        ->and($completed->report->html)->toContain('Rapport Klant')
        ->and($completed->report->html)->toContain('Korte samenvatting')
        ->and($completed->report->html)->toContain('Gewenste functie: Alleen koelen.')
        ->and($completed->report->html)->toContain('Opgegeven omvang: 1 binnenunit voor Woonkamer.')
        ->and($completed->report->html)->toContain('Testantwoord request_reason')
        ->and($completed->attentionPoints->pluck('code')->all())
        ->toContain('no_free_group')
        ->toContain('condensate_pump_likely');
});

test('installer can preview the generated report through the protected endpoint', function () {
    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    $completed = app(CompleteIntake::class)->handle($intake->fresh());

    $this->actingAs($completed->creator)
        ->get(route('intakes.report', $completed))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Content-Security-Policy')
        ->assertSee('Korte samenvatting')
        ->assertSee('Gewenste functie: Alleen koelen.');
});

test('customer wizard complete refuses incomplete and succeeds when filled', function () {
    $incomplete = makePhase5Intake();

    Livewire::test(IntakeWizard::class, ['token' => $incomplete->access_token])
        ->call('complete')
        ->assertSet('completed', false)
        ->assertSet('showMissing', true);

    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->call('complete')
        ->assertSet('completed', true)
        ->assertSee('Bedankt');

    expect($intake->fresh()->status)->toBe(IntakeStatus::Completed);
});

test('template republish does not change pinned completed intake report', function () {
    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    $pinnedVersionId = $intake->intake_template_version_id;

    app(CompleteIntake::class)->handle($intake->fresh());
    $reportHtml = $intake->fresh()->report->html;

    // Publish a fresh version number to prove the completed intake stays pinned.
    $config = require database_path('data/templates/airco/v2.php');
    $config['version'] = 4;
    $config['change_notes'] = 'Fase 5 pin-test versie';
    $config['sections'][0]['questions'][0]['label'] = 'GEWIJZIGDE VRAAGLABEL';

    $v4 = app(PublishIntakeTemplateFromConfig::class)->handle($config);

    expect($v4->version)->toBe(4)
        ->and($intake->fresh()->intake_template_version_id)->toBe($pinnedVersionId)
        ->and($intake->fresh()->report->html)->toBe($reportHtml)
        ->and($intake->fresh()->report->html)->not->toContain('GEWIJZIGDE VRAAGLABEL');
});

test('installer can submit a review decision', function () {
    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());

    $reviewer = User::factory()->create();

    $this->actingAs($reviewer)
        ->post(route('intakes.review', $intake), [
            'decision' => ReviewDecision::PrepareQuote->value,
            'site_visit_needed' => '0',
            'enough_information' => '1',
            'summary' => 'Klaar voor offerte.',
        ])
        ->assertRedirect(route('intakes.show', $intake));

    $intake->refresh();

    expect($intake->status)->toBe(IntakeStatus::Reviewed)
        ->and($intake->reviewed_at)->not->toBeNull()
        ->and($intake->review->decision)->toBe(ReviewDecision::PrepareQuote)
        ->and($intake->review->summary)->toBe('Klaar voor offerte.')
        ->and($intake->activityEvents()->where('event', 'intake_reviewed')->latest('id')->firstOrFail()->properties)
        ->toMatchArray([
            'decision' => ReviewDecision::PrepareQuote->value,
            'enough_information' => true,
        ]);

    $this->actingAs($reviewer)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Opnamerapport')
        ->assertSee('Offerte voorbereiden');
});

test('submit review action rejects non-completed intakes', function () {
    $intake = makePhase5Intake();
    $reviewer = User::factory()->create();

    expect(fn () => app(SubmitIntakeReview::class)->handle($intake, $reviewer, [
        'decision' => ReviewDecision::PrepareQuote,
    ]))->toThrow(ValidationException::class);
});

test('need more information creates a targeted customer round and sends the existing link', function () {
    Mail::fake();
    config(['mail.default' => 'smtp']);

    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());
    $reviewer = User::factory()->create();

    $this->actingAs($reviewer)
        ->post(route('intakes.review', $intake), [
            'decision' => ReviewDecision::NeedMoreInfo->value,
            'summary' => 'Alleen deze twee punten ontbreken.',
            'follow_up_items' => [
                ['type' => FollowUpItemType::Text->value, 'prompt' => 'Waar loopt de condensafvoer precies heen?'],
                ['type' => FollowUpItemType::Photo->value, 'prompt' => 'Maak een foto van de vrije groep in de meterkast.'],
                ['type' => FollowUpItemType::Text->value, 'prompt' => ''],
            ],
        ])
        ->assertRedirect(route('intakes.show', $intake));

    $intake->refresh();
    $round = $intake->followUpRounds()->with('items')->firstOrFail();

    expect($intake->status)->toBe(IntakeStatus::AwaitingCustomer)
        ->and($intake->review->decision)->toBe(ReviewDecision::NeedMoreInfo)
        ->and($intake->review->enough_information)->toBeFalse()
        ->and($round->status)->toBe(FollowUpRoundStatus::Open)
        ->and($round->round_number)->toBe(1)
        ->and($round->items)->toHaveCount(2)
        ->and($round->items->pluck('type')->all())->toBe([
            FollowUpItemType::Text,
            FollowUpItemType::Photo,
        ]);

    Mail::assertSent(CustomerFollowUpRequestMail::class, function (CustomerFollowUpRequestMail $mail) use ($intake, $round): bool {
        return $mail->intake->is($intake) && $mail->round->is($round);
    });

    $this->get(route('customer.intake.show', $intake->access_token))
        ->assertOk()
        ->assertSee('Waar loopt de condensafvoer precies heen?')
        ->assertDontSee('Wat is de reden van uw aanvraag?');
});

test('customer completes text and photo follow up and dossier returns for review', function () {
    Mail::fake();
    Queue::fake();
    config(['mail.default' => 'smtp']);

    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());
    $reviewer = User::factory()->create();

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $reviewer, [
        'decision' => ReviewDecision::NeedMoreInfo,
        'follow_up_items' => [
            ['type' => FollowUpItemType::Text, 'prompt' => 'Welke route heeft uw voorkeur?'],
            ['type' => FollowUpItemType::Photo, 'prompt' => 'Fotografeer de doorvoer van dichtbij.'],
        ],
    ]);

    $intake->refresh();
    $round = $intake->followUpRounds()->with('items')->firstOrFail();
    $textItem = $round->items->firstWhere('type', FollowUpItemType::Text);
    $photoItem = $round->items->firstWhere('type', FollowUpItemType::Photo);

    expect($textItem)->not->toBeNull()
        ->and($photoItem)->not->toBeNull();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSet('followUpMode', true)
        ->assertSee('Welke route heeft uw voorkeur?')
        ->set('followUpResponses.'.$textItem->id, 'Via de linker zijgevel.')
        ->call('nextFollowUp')
        ->assertSee('Fotografeer de doorvoer van dichtbij.')
        ->call('completeFollowUp')
        ->assertHasErrors(['follow_up'])
        ->assertSee('Voeg eerst minimaal één foto toe.')
        ->set('followUpPhotoFiles.'.$photoItem->id, UploadedFile::fake()->image('doorvoer.jpg', 800, 600))
        ->assertHasNoErrors()
        ->call('completeFollowUp')
        ->assertHasNoErrors()
        ->assertSet('completed', true)
        ->assertSee('Je aanvulling is toegevoegd aan het dossier.');

    $intake->refresh();
    $round->refresh();

    expect($intake->status)->toBe(IntakeStatus::Completed)
        ->and($intake->reviewed_at)->toBeNull()
        ->and($round->status)->toBe(FollowUpRoundStatus::Completed)
        ->and($round->completed_at)->not->toBeNull()
        ->and($textItem->fresh()->response_text)->toBe('Via de linker zijgevel.')
        ->and($photoItem->uploads()->count())->toBe(1)
        ->and($intake->report->html)->toContain('Aanvullende informatie')
        ->and($intake->report->html)->toContain('Via de linker zijgevel.')
        ->and($intake->report->html)->toContain('1 aanvullende foto')
        ->and($intake->report->html)->toContain('Aangeleverde foto’s en bestanden')
        ->and($intake->report->html)->toContain('Fotografeer de doorvoer van dichtbij.')
        ->and($intake->report->html)->toContain('aanvulling ronde 1')
        ->and($intake->report->html)->toContain('Bron: aangeleverd door klant')
        ->and($intake->report->html)->toContain('Beoordeel de aangeleverde aanvulling');

    Mail::assertSent(InstallerIntakeCompletedMail::class);

    $this->get(route('customer.intake.show', $intake->access_token))->assertNotFound();
});

test('customer can add a requested PDF document to the protected dossier', function () {
    Mail::fake();
    Queue::fake();
    Storage::fake((string) config('filesystems.media', 'local'));
    config(['mail.default' => 'smtp']);

    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());
    $reviewer = User::factory()->create();

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $reviewer, [
        'decision' => ReviewDecision::NeedMoreInfo,
        'follow_up_items' => [[
            'type' => FollowUpItemType::Document,
            'prompt' => 'Upload de plattegrond als PDF.',
        ]],
    ]);

    $item = $intake->followUpRounds()->firstOrFail()->items()->firstOrFail();
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSee('Upload de plattegrond als PDF.')
        ->call('completeFollowUp')
        ->assertHasErrors(['follow_up'])
        ->assertSee('Voeg eerst minimaal één PDF-document toe.')
        ->set(
            'followUpDocumentFiles.'.$item->id,
            UploadedFile::fake()->createWithContent('geen-pdf.pdf', 'dit is geen pdf'),
        )
        ->assertHasErrors(['followUpDocumentFiles.'.$item->id])
        ->assertSee('Alleen een geldig PDF-document is toegestaan.')
        ->set(
            'followUpDocumentFiles.'.$item->id,
            UploadedFile::fake()->createWithContent('plattegrond.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
        )
        ->assertHasNoErrors()
        ->assertSee('plattegrond.pdf')
        ->call('completeFollowUp')
        ->assertHasNoErrors()
        ->assertSet('completed', true);

    $upload = $item->uploads()->firstOrFail();
    $intake->refresh();

    expect($upload->mime_type)->toBe('application/pdf')
        ->and($upload->original_filename)->toBe('plattegrond.pdf')
        ->and($upload->usability_verdict)->toBeNull()
        ->and($intake->report->html)->toContain('plattegrond.pdf')
        ->and($intake->report->html)->toContain('aangeleverd document')
        ->and($intake->report->html)->toContain('Upload de plattegrond als PDF.');

    $report = app(GenerateIntakePdf::class)->handle($intake);

    expect($report)->not->toBeNull()
        ->and($report->hasPdf())->toBeTrue()
        ->and(Storage::disk((string) $report->pdf_disk)->get((string) $report->pdf_path))->toStartWith('%PDF');

    $this->actingAs($reviewer);

    $this->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Foto’s en bestanden')
        ->assertSee('plattegrond.pdf')
        ->assertSee('PDF');

    $this->get(route('intakes.report', $intake))
        ->assertOk()
        ->assertSee('plattegrond.pdf')
        ->assertSee('Aangeleverde foto’s en bestanden');

    $this->get(route('installer.uploads.show', [$intake, $upload]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('plattegrond.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $component->assertSee('Je aanvulling is toegevoegd aan het dossier.');
});

test('follow up photo quality hint repeats the installers exact photo request', function () {
    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());

    app(SubmitIntakeReview::class)->handle($intake->fresh(), User::factory()->create(), [
        'decision' => ReviewDecision::NeedMoreInfo,
        'follow_up_items' => [[
            'type' => FollowUpItemType::Photo,
            'prompt' => 'Fotografeer de condensafvoer van dichtbij en met de aansluiting zichtbaar.',
        ]],
    ]);

    $item = $intake->followUpRounds()->firstOrFail()->items()->firstOrFail();
    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('followUpPhotoFiles.'.$item->id, darkFollowUpUpload())
        ->assertHasNoErrors()
        ->assertSee('Maak een nieuwe foto met meer licht.')
        ->assertSee('Fotografeer de condensafvoer van dichtbij en met de aansluiting zichtbaar.');

    expect($item->uploads()->firstOrFail()->usability_verdict)->toBe(PhotoUsabilityVerdict::TooDark);

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertSee('Maak een nieuwe foto met meer licht.')
        ->assertSee('condensafvoer van dichtbij')
        ->assertSee('aansluiting zichtbaar');
});

test('need more information requires concrete items and caps follow up rounds', function () {
    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());
    $reviewer = User::factory()->create();

    $this->actingAs($reviewer)
        ->post(route('intakes.review', $intake), [
            'decision' => ReviewDecision::NeedMoreInfo->value,
            'follow_up_items' => [],
        ])
        ->assertSessionHasErrors('follow_up_items');

    for ($roundNumber = 1; $roundNumber <= 3; $roundNumber++) {
        $reviewable = Intake::query()->findOrFail($intake->id);
        $reviewable->update(['status' => IntakeStatus::Completed]);

        app(SubmitIntakeReview::class)->handle($reviewable->fresh(), $reviewer, [
            'decision' => ReviewDecision::NeedMoreInfo,
            'follow_up_items' => [[
                'type' => FollowUpItemType::Text,
                'prompt' => 'Gerichte vraag '.$roundNumber,
            ]],
        ]);

        $intake->followUpRounds()->where('round_number', $roundNumber)->update([
            'status' => FollowUpRoundStatus::Completed,
            'completed_at' => now(),
        ]);
        Intake::query()->whereKey($intake->id)->update(['status' => IntakeStatus::Completed->value]);
    }

    $reviewable = Intake::query()->findOrFail($intake->id);
    $reviewable->update(['status' => IntakeStatus::Completed]);

    expect(fn () => app(SubmitIntakeReview::class)->handle($reviewable->fresh(), $reviewer, [
        'decision' => ReviewDecision::NeedMoreInfo,
        'follow_up_items' => [[
            'type' => FollowUpItemType::Text,
            'prompt' => 'Vierde ronde',
        ]],
    ]))->toThrow(ValidationException::class);

    expect($intake->followUpRounds()->count())->toBe(3);
});

test('expired token cannot open an active follow up round', function () {
    $intake = makePhase5Intake();
    fillIntakeUntilComplete($intake);
    app(CompleteIntake::class)->handle($intake->fresh());
    $reviewer = User::factory()->create();

    app(SubmitIntakeReview::class)->handle($intake->fresh(), $reviewer, [
        'decision' => ReviewDecision::NeedMoreInfo,
        'follow_up_items' => [[
            'type' => FollowUpItemType::Text,
            'prompt' => 'Nog één vraag',
        ]],
    ]);

    $intake->update(['token_expires_at' => now()->subMinute()]);

    $this->get(route('customer.intake.show', $intake->access_token))->assertNotFound();
});
