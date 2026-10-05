<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoContentAssessment;
use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\InstallerPhotoGalleryBuilder;
use App\Domains\Intake\Support\InstallerEvidencePresenter;
use App\Domains\Intake\Support\UploadSupersessionResolver;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\FollowUpRoundStatus;
use App\Enums\IntakeStatus;
use App\Enums\PhotoAssessmentStatus;
use App\Enums\PhotoUsabilityVerdict;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function evidenceLabelIntake(User $user): Intake
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Bewijs Labels',
        'customer_email' => 'bewijs-labels@example.com',
        'address_line' => 'Labelstraat 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    $intake->update(['status' => IntakeStatus::InProgress]);

    return $intake->fresh() ?? $intake;
}

function evidenceOpaque(string $type, int $id): string
{
    return $type.'_'.substr(hash_hmac('sha256', (string) $id, (string) config('app.key')), 0, 16);
}

function markFuseboxOk(IntakeUpload $upload): void
{
    $upload->update([
        'assessment_status' => PhotoAssessmentStatus::Assessed,
        'content_assessment' => PhotoContentAssessment::ok(PhotoSubject::Fusebox)->toArray(),
        'usability_verdict' => PhotoUsabilityVerdict::Ok,
    ]);
}

test('attention evidence shows Dutch labels and never raw internal keys', function () {
    $user = User::factory()->create();
    $intake = evidenceLabelIntake($user);
    $store = app(StoreIntakeUpload::class);

    $upload = $store->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 640, 480),
    );
    markFuseboxOk($upload);

    $fact = IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'fusebox_photo_assessment',
        'label' => 'Automatische beoordeling meterkastfoto',
        'value' => [
            'empty_module_space' => 'visible',
            'phase' => 'one_phase',
            'upload_ids' => [$upload->id],
        ],
        'source' => 'ai_photo',
        'confidence' => 'medium',
        'captured_at' => now(),
    ]);

    $rawReference = 'fusebox_photo_assessment@fact:'.evidenceOpaque('fact', $fact->id);

    $citations = app(InstallerEvidencePresenter::class)->presentAttentionEvidence($intake->fresh(), [
        ['source_type' => 'external_fact', 'reference' => $rawReference],
        ['source_type' => 'answer', 'reference' => 'ceiling_height_m'],
        ['source_type' => 'upload', 'reference' => 'fusebox_photo@upload:'.evidenceOpaque('upload', $upload->id)],
    ]);

    expect($citations)->toHaveCount(3)
        ->and(mb_strtolower($citations[0]['label']))->toContain('meterkast')
        ->and($citations[0]['url'])->not->toBeNull()
        ->and($citations[1]['label'])->toStartWith('Antwoord:')
        ->and(mb_strtolower($citations[2]['label']))->toContain('meterkast');

    foreach ($citations as $citation) {
        expect($citation['label'])
            ->not->toContain('@fact:')
            ->not->toContain('@upload:')
            ->not->toContain('fusebox_photo_assessment')
            ->not->toMatch('/fact_[a-f0-9]{12,}/');
    }

    IntakeAttentionPoint::query()->create([
        'intake_id' => $intake->id,
        'source' => AttentionPointSource::Ai,
        'code' => 'check_fusebox',
        'label' => 'Controleer de meterkastnogmaals.',
        'status' => AttentionPointStatus::Proposed,
        'ai_confidence' => 'medium',
        'evidence' => [
            ['source_type' => 'external_fact', 'reference' => $rawReference],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Foto van de meterkast', false)
        ->assertDontSee($rawReference, false)
        ->assertDontSee('fusebox_photo_assessment@fact:', false);
});

test('stale fact reference prefers current fusebox evidence label', function () {
    $user = User::factory()->create();
    $intake = evidenceLabelIntake($user);
    $store = app(StoreIntakeUpload::class);

    $upload = $store->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-nieuw.jpg', 640, 480),
    );
    markFuseboxOk($upload);

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'fusebox_photo_assessment',
        'label' => 'Automatische beoordeling meterkastfoto',
        'value' => ['upload_ids' => [$upload->id], 'empty_module_space' => 'visible'],
        'source' => 'ai_photo',
        'confidence' => 'medium',
        'captured_at' => now(),
    ]);

    $staleReference = 'fusebox_photo_assessment@fact:'.evidenceOpaque('fact', 999999);

    $citations = app(InstallerEvidencePresenter::class)->presentAttentionEvidence($intake->fresh(), [
        ['source_type' => 'external_fact', 'reference' => $staleReference],
    ]);

    expect($citations)->toHaveCount(1)
        ->and(mb_strtolower($citations[0]['label']))->toContain('meterkast')
        ->and($citations[0]['label'])->not->toContain('@fact:')
        ->and($citations[0]['url'])->not->toBeNull();
});

test('gallery marks original fusebox photo as replaced after follow-up round 1', function () {
    $user = User::factory()->create();
    $intake = evidenceLabelIntake($user);
    $store = app(StoreIntakeUpload::class);

    $old = $store->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast-oud.jpg', 640, 480),
    );
    markFuseboxOk($old);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een leesbare foto van de volledige meterkast.',
        'decision_area_key' => 'power',
        'dossier_subject_id' => null,
    ]]);

    $item = $round->items()->firstOrFail();
    $path = 'private/intakes/'.$intake->id.'/follow-up-meterkast.jpg';
    Storage::disk((string) config('filesystems.media', 'local'))->put($path, 'fake-image');
    $new = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'follow_up_upload',
        'section_instance_key' => null,
        'intake_follow_up_item_id' => $item->id,
        'disk' => (string) config('filesystems.media', 'local'),
        'path' => $path,
        'original_filename' => 'meterkast-nieuw.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1200,
        'sort_order' => 0,
    ]);
    markFuseboxOk($new);

    $item->update(['answered_at' => now()]);
    $round->update([
        'status' => FollowUpRoundStatus::Completed,
        'completed_at' => now(),
    ]);
    $intake->update(['status' => IntakeStatus::InProgress]);

    $supersessions = app(UploadSupersessionResolver::class)->resolve($intake->fresh());

    expect($supersessions[(int) $old->id]['superseded'])->toBeTrue()
        ->and($supersessions[(int) $old->id]['supersession_label'])->toBe('Vervangen door ronde 1')
        ->and($supersessions[(int) $old->id]['replaced_by_upload_id'])->toBe((int) $new->id)
        ->and($supersessions[(int) $new->id]['superseded'])->toBeFalse();

    $groups = app(InstallerPhotoGalleryBuilder::class)->handle($intake->fresh());
    $flat = collect($groups)->flatMap(static fn (array $group): array => $group['uploads']);
    $oldItem = $flat->first(static fn (array $row): bool => (int) $row['upload']->id === (int) $old->id);
    $newItem = $flat->first(static fn (array $row): bool => (int) $row['upload']->id === (int) $new->id);

    expect($oldItem['superseded'])->toBeTrue()
        ->and($oldItem['supersession_label'])->toBe('Vervangen door ronde 1')
        ->and($newItem['superseded'])->toBeFalse();

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Vervangen door ronde 1', false)
        ->assertSee('data-testid="gallery-superseded"', false);

    // Citations that still point at the old upload prefer the current round photo.
    $citations = app(InstallerEvidencePresenter::class)->presentAttentionEvidence($intake->fresh(), [
        [
            'source_type' => 'upload',
            'reference' => 'fusebox_photo@upload:'.evidenceOpaque('upload', $old->id),
        ],
    ]);

    expect($citations[0]['label'])->toContain('ronde 1')
        ->and($citations[0]['upload_id'])->toBe((int) $new->id)
        ->and($citations[0]['superseded'])->toBeFalse();
});

test('synthesis dossier_image references render as Dutch photo labels', function () {
    $user = User::factory()->create();
    $intake = evidenceLabelIntake($user);
    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('meterkast.jpg', 640, 480),
    );
    markFuseboxOk($upload);

    $citations = app(InstallerEvidencePresenter::class)->presentSynthesisReferences(
        $intake->fresh(),
        ['dossier_image:'.$upload->id, 'dossier_image:999999'],
    );

    expect($citations)->toHaveCount(2)
        ->and(mb_strtolower($citations[0]['label']))->toContain('meterkast')
        ->and($citations[0]['url'])->not->toBeNull()
        ->and($citations[1]['label'])->toBe('Foto')
        ->and($citations[0]['label'])->not->toContain('dossier_image:');
});
