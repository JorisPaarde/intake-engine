<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\StoreIntakeUpload;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function makeUploadIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
    ]);
}

test('customer can upload and preview a photo for a photo question', function () {
    $intake = makeUploadIntake();
    $file = UploadedFile::fake()->image('meterkast.jpg', 800, 600);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $file,
    );

    expect($upload)->toBeInstanceOf(IntakeUpload::class)
        ->and(Storage::disk((string) config('filesystems.media'))->exists($upload->path))->toBeTrue()
        ->and($intake->fresh()->answers()->where('question_key', 'fusebox_photo')->value('value'))
        ->toMatchArray(['upload_ids' => [$upload->id]]);

    $this->get(route('customer.uploads.show', [
        'token' => $intake->access_token,
        'upload' => $upload,
    ]))->assertOk();
});

test('upload rejects invalid mime types and oversized files', function () {
    $intake = makeUploadIntake();

    expect(fn () => app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
    ))->toThrow(ValidationException::class);

    config(['intake.uploads.max_kilobytes' => 100]);

    expect(fn () => app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('big.jpg')->size(200),
    ))->toThrow(ValidationException::class);
});

test('unauthorized users cannot view another intakes upload', function () {
    $ownerIntake = makeUploadIntake();
    $otherIntake = makeUploadIntake();

    $upload = app(StoreIntakeUpload::class)->handle(
        $ownerIntake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('secret.jpg'),
    );

    $this->get(route('customer.uploads.show', [
        'token' => $otherIntake->access_token,
        'upload' => $upload,
    ]))->assertNotFound();

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('installer.uploads.show', [$otherIntake, $upload]))
        ->assertNotFound();
});

test('installer can view an upload belonging to an intake', function () {
    $intake = makeUploadIntake();
    $installer = User::query()->findOrFail($intake->created_by);

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->image('ok.jpg'),
    );

    $this->actingAs($installer)
        ->get(route('installer.uploads.show', [$intake, $upload]))
        ->assertOk();
});

test('livewire wizard accepts a photo upload', function () {
    $intake = makeUploadIntake();
    $file = UploadedFile::fake()->image('kamer.jpg');

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.fusebox_photo', $file)
        ->assertHasNoErrors()
        ->assertSet('saveMessage', 'Foto opgeslagen');

    expect(IntakeUpload::query()->where('intake_id', $intake->id)->count())->toBe(1);
});
