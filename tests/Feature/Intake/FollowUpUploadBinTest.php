<?php

declare(strict_types=1);

use App\Domains\AI\Actions\SummarizeIntake;
use App\Domains\AI\Actions\SynthesizeSurveyDossier;
use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\AI\Jobs\SuggestAttentionPointsJob;
use App\Domains\AI\Jobs\SynthesizeSurveyDossierJob;
use App\Domains\AI\Services\IntakeAttentionContextBuilder;
use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CompleteFollowUpRound;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\DeleteFollowUpUpload;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Models\DossierEvidenceLink;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * BL-147 (UX #16.4, besluit 8 okt): een weggehaalde aanvulfoto staat in de
 * “prullenbak” (soft delete) tot hij echt gewist wordt: volgend bezoek aan de
 * klantlink, “Aanvulling versturen” of de uurlijkse opruiming (> 10 min).
 * In de prullenbak telt hij nergens mee: rapport, AI-samenvatting, dossier-AI.
 */

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    FakeAiClient::reset();
    Mail::fake();
    config(['ai.provider' => 'fake', 'ai.dossier.enabled' => false]);
});

afterEach(function () {
    FakeAiClient::reset();
});

/**
 * @return array{0: Intake, 1: IntakeFollowUpRound, 2: IntakeFollowUpItem, 3: IntakeUpload, 4: IntakeUpload}
 */
function binIntakeWithTwoPhotos(): array
{
    Queue::fake([AssessUploadedPhotoJob::class]);
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Prullenbak Klant',
        'customer_email' => 'bin@example.com',
        'address_line' => 'Binlaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);
    app(DossierManager::class)->initialize($intake);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een foto van de meterkast.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => app(DossierManager::class)->root($intake)->id,
    ]]);
    $item = $round->items()->firstOrFail();
    $disk = (string) config('filesystems.media', 'local');
    $bytes = (string) file_get_contents(base_path('tests/fixtures/klanttest-20261002/meterkast-flow.jpg'));

    $make = function (string $name, int $sort) use ($intake, $item, $disk, $bytes): IntakeUpload {
        $path = 'intakes/'.$intake->uuid.'/follow-up/'.$name;
        Storage::disk($disk)->put($path, $bytes.$name);
        $upload = IntakeUpload::query()->create([
            'intake_id' => $intake->id,
            'question_key' => 'follow_up_'.$item->id,
            'intake_follow_up_item_id' => $item->id,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $name,
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($bytes) + strlen($name),
            'checksum' => hash('sha256', $bytes.$name),
            'sort_order' => $sort,
        ]);
        $upload->storeContentAssessment(PhotoContentAssessment::ok(PhotoSubject::Fusebox));

        return $upload->fresh();
    };

    $kept = $make('meterkast-goed.jpg', 0);
    $binned = $make('meterkast-prullenbak.jpg', 1);
    $item->update(['answered_at' => now()]);

    // Bewijslink bestond al voor het weghalen (dossier-sync liep met beide foto's).
    app(DossierManager::class)->initialize($intake->fresh());
    expect(DossierEvidenceLink::query()->where('evidence_type', 'intake_upload')->where('evidence_id', $binned->id)->exists())->toBeTrue();

    // Weghalen zonder ongedaan maken; tabblad dicht binnen 8 s: alleen soft delete.
    app(DeleteFollowUpUpload::class)->softRemove($intake->fresh(), $item->fresh(), $binned);

    return [$intake->fresh(), $round->fresh(), $item->fresh(), $kept, $binned];
}

test('een foto in de prullenbak komt niet in rapport, AI-samenvatting, dossier-AI of aandachtspunten', function () {
    [$intake, $round, $item, $kept, $binned] = binIntakeWithTwoPhotos();

    $binnedRow = IntakeUpload::withTrashed()->findOrFail($binned->id);
    expect($binnedRow->trashed())->toBeTrue()
        ->and($binnedRow->purged_at)->toBeNull()
        ->and(Storage::disk($binned->disk)->exists($binned->path))->toBeTrue();

    // Rapport (zoals na afronden van de ronde, maar zonder de opruiming ervoor).
    $round->update(['status' => FollowUpRoundStatus::Completed, 'completed_at' => now()]);
    $version = $intake->templateVersion()->firstOrFail();
    $html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $version);
    expect($html)->toContain('1 aanvullende foto')
        ->not->toContain('2 aanvullende foto')
        ->not->toContain('data-intake-upload-id="'.$binned->id.'"')
        ->not->toContain('meterkast-prullenbak.jpg');

    // Aandachtspunten-context (ook de basis van de dossier-AI).
    $context = app(IntakeAttentionContextBuilder::class)->build($intake->fresh());
    $followUpUploads = collect($context['follow_up'])->flatMap(fn (array $r): array => $r['items'])->flatMap(fn (array $i): array => $i['uploads']);
    expect($followUpUploads)->toHaveCount(1)
        ->and(collect($context['uploads'])->where('context', 'follow_up'))->toHaveCount(1);

    // AI-samenvatting: wat er naar de AI gaat.
    FakeAiClient::respondUsing(fn (): array => ['summary' => 'Samenvatting', 'highlights' => []]);
    app(SummarizeIntake::class)->handle($intake->fresh());
    $summaryInput = FakeAiClient::lastRequest()?->input ?? [];
    expect(collect($summaryInput['follow_up'] ?? [])->flatMap(fn (array $r): array => $r['items'])->sum('upload_count'))->toBe(1);

    // Dossier-AI (synthese met beelden).
    config(['ai.dossier.enabled' => true]);
    FakeAiClient::reset();
    FakeAiClient::respondUsing(fn (): array => []);
    app(SynthesizeSurveyDossier::class)->handle($intake->fresh());
    $request = FakeAiClient::lastRequest();
    $references = collect($request?->input['image_manifest'] ?? [])->pluck('reference')->all();
    expect($references)->toContain('dossier_image:'.$kept->id)
        ->not->toContain('dossier_image:'.$binned->id)
        ->and($request?->images)->toHaveCount(count($references));
});

test('Aanvulling versturen wist wat nog in de prullenbak staat; rapport en AI zien alleen de bewaarde foto', function () {
    [$intake, $round, $item, $kept, $binned] = binIntakeWithTwoPhotos();
    Queue::fake([SynthesizeSurveyDossierJob::class, SuggestAttentionPointsJob::class, GenerateIntakePdfJob::class]);

    app(CompleteFollowUpRound::class)->handle($intake, $round, []);

    $binnedRow = IntakeUpload::withTrashed()->findOrFail($binned->id);
    expect($binnedRow->purged_at)->not->toBeNull()
        ->and(Storage::disk($binned->disk)->exists($binned->path))->toBeFalse()
        ->and(Storage::disk($kept->disk)->exists($kept->path))->toBeTrue()
        ->and(DossierEvidenceLink::query()->where('evidence_type', 'intake_upload')->where('evidence_id', $binned->id)->exists())->toBeFalse()
        ->and(DossierEvidenceLink::query()->where('evidence_type', 'intake_upload')->where('evidence_id', $kept->id)->exists())->toBeTrue()
        ->and(IntakeActivityEvent::query()->where('event', 'follow_up_upload_deleted')->where('actor_type', 'customer')->count())->toBe(1);

    $html = app(GenerateIntakeReportHtml::class)->handle($intake->fresh(), $intake->templateVersion()->firstOrFail());
    expect($html)->toContain('1 aanvullende foto')
        ->not->toContain('meterkast-prullenbak.jpg');
    Queue::assertPushed(SynthesizeSurveyDossierJob::class);
});

test('volgend bezoek aan de klantlink wist wat nog in de prullenbak staat', function () {
    [$intake, , , $kept, $binned] = binIntakeWithTwoPhotos();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->assertDontSee('Ongedaan maken');

    expect(IntakeUpload::withTrashed()->findOrFail($binned->id)->purged_at)->not->toBeNull()
        ->and(Storage::disk($binned->disk)->exists($binned->path))->toBeFalse()
        ->and(IntakeUpload::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Storage::disk($kept->disk)->exists($kept->path))->toBeTrue();

    // Een tweede bezoek doet niets meer (geen dubbele activiteit).
    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token]);
    expect(IntakeActivityEvent::query()->where('event', 'follow_up_upload_deleted')->count())->toBe(1);
});

test('ongedaan maken na het echte wissen (ander tabblad) zet niets terug', function () {
    [$intake, , $item, , $binned] = binIntakeWithTwoPhotos();
    app(DeleteFollowUpUpload::class)->purgePendingFor($intake);

    expect(fn () => app(DeleteFollowUpUpload::class)->restore($intake, $item, $binned->id, now()))
        ->toThrow(ValidationException::class, 'Ongedaan maken mislukt.');
    expect(IntakeUpload::withTrashed()->findOrFail($binned->id)->trashed())->toBeTrue();
});

test('uurlijkse opruiming wist alleen aanvulfoto’s die langer dan 10 minuten in de prullenbak staan', function () {
    [$intake, , , $kept, $binned] = binIntakeWithTwoPhotos();

    // Hoofdwizard-foto die al direct gewist is: nooit opnieuw verwerken.
    $wizardUpload = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'room_photos',
        'disk' => $kept->disk,
        'path' => 'intakes/'.$intake->uuid.'/room.jpg',
        'original_filename' => 'room.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'sort_order' => 0,
    ]);
    $wizardUpload->forceFill(['purged_at' => now()])->saveQuietly();
    $wizardUpload->delete();

    $this->artisan('photos:purge-removed')
        ->expectsOutput('Purged 0 removed follow-up upload(s).')
        ->expectsOutput('Purged 0 removed wizard upload(s).')
        ->assertSuccessful();
    expect(IntakeUpload::withTrashed()->findOrFail($binned->id)->purged_at)->toBeNull();

    $this->travel(11)->minutes();
    $this->artisan('photos:purge-removed')
        ->expectsOutput('Purged 1 removed follow-up upload(s).')
        ->expectsOutput('Purged 0 removed wizard upload(s).')
        ->assertSuccessful();
    $this->artisan('photos:purge-removed')
        ->expectsOutput('Purged 0 removed follow-up upload(s).')
        ->expectsOutput('Purged 0 removed wizard upload(s).')
        ->assertSuccessful();

    expect(IntakeUpload::withTrashed()->findOrFail($binned->id)->purged_at)->not->toBeNull()
        ->and(Storage::disk($binned->disk)->exists($binned->path))->toBeFalse()
        ->and(Storage::disk($kept->disk)->exists($kept->path))->toBeTrue()
        ->and(IntakeActivityEvent::query()->where('event', 'follow_up_upload_deleted')->get()->map(
            fn (IntakeActivityEvent $event): array => [$event->actor_type, $event->properties['upload_id'] ?? null],
        )->all())->toBe([['system', $binned->id]]);

    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'photos:purge-removed'));
    expect($event)->toBeInstanceOf(Event::class)
        ->and($event->expression)->toBe('0 * * * *');
});

test('uurlijkse opruiming wist ook hoofdwizard-foto’s die langer in de prullenbak staan', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Wizard Prullenbak',
        'customer_email' => 'wizard-bin@example.com',
        'address_line' => 'Binlaan 2',
        'address_postal_code' => '1000AA',
        'address_house_number' => 2,
        'address_city' => 'Amsterdam',
    ]);
    $disk = (string) config('filesystems.media', 'local');
    $path = 'intakes/'.$intake->uuid.'/fusebox.jpg';
    Storage::disk($disk)->put($path, 'wizard-bin');
    $upload = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'fusebox_photo',
        'disk' => $disk,
        'path' => $path,
        'original_filename' => 'fusebox.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'sort_order' => 0,
    ]);
    $upload->delete();

    $this->travel(11)->minutes();
    $this->artisan('photos:purge-removed')
        ->expectsOutput('Purged 0 removed follow-up upload(s).')
        ->expectsOutput('Purged 1 removed wizard upload(s).')
        ->assertSuccessful();

    expect(IntakeUpload::withTrashed()->findOrFail($upload->id)->purged_at)->not->toBeNull()
        ->and(Storage::disk($disk)->exists($path))->toBeFalse();
});
