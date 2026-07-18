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

function makeHeicUploadedFile(string $name = 'iphone.heic'): UploadedFile
{
    $fixture = base_path('tests/Fixtures/sample.heic');

    if (! is_file($fixture) || (int) filesize($fixture) === 0) {
        throw new RuntimeException('HEIC-testfixture ontbreekt (tests/Fixtures/sample.heic).');
    }

    if (! class_exists(Imagick::class) || Imagick::queryFormats('HEIC') === []) {
        throw new RuntimeException('Imagick kan in deze omgeving geen HEIC lezen.');
    }

    try {
        $probe = new Imagick($fixture);
        $probe->clear();
        $probe->destroy();
    } catch (Throwable $exception) {
        throw new RuntimeException('Imagick kan de HEIC-fixture niet openen: '.$exception->getMessage(), 0, $exception);
    }

    $contents = file_get_contents($fixture);

    if ($contents === false || $contents === '') {
        throw new RuntimeException('HEIC-fixture kon niet worden gelezen.');
    }

    // Livewire-test upload verwacht Illuminate\Http\Testing\File (publieke $name).
    return tap(UploadedFile::fake()->createWithContent($name, $contents), function ($file): void {
        $file->mimeTypeToReport = 'image/heic';
    });
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

test('heic uploads are converted to stored jpeg files with working preview', function () {
    try {
        $file = makeHeicUploadedFile();
    } catch (RuntimeException $exception) {
        $this->markTestSkipped($exception->getMessage());
    }

    $intake = makeUploadIntake();

    $upload = app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        $file,
    );

    expect($upload->mime_type)->toBe('image/jpeg')
        ->and($upload->path)->toEndWith('.jpg')
        ->and($upload->size_bytes)->toBeGreaterThan(0)
        ->and(Storage::disk((string) config('filesystems.media'))->exists($upload->path))->toBeTrue();

    $this->get(route('customer.uploads.show', [
        'token' => $intake->access_token,
        'upload' => $upload,
    ]))->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
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

test('upload rejects fake heic files that are not photos', function () {
    $intake = makeUploadIntake();

    expect(fn () => app(StoreIntakeUpload::class)->handle(
        $intake,
        'fusebox_photo',
        null,
        UploadedFile::fake()->create('contract.heic', 100, 'application/pdf'),
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

test('livewire wizard accepts a heic photo upload', function () {
    try {
        $file = makeHeicUploadedFile('kamer.heic');
    } catch (RuntimeException $exception) {
        $this->markTestSkipped($exception->getMessage());
    }

    $intake = makeUploadIntake();

    Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.fusebox_photo', $file)
        ->assertHasNoErrors()
        ->assertSet('saveMessage', 'Foto opgeslagen');

    $upload = IntakeUpload::query()->where('intake_id', $intake->id)->firstOrFail();

    expect($upload->mime_type)->toBe('image/jpeg')
        ->and($upload->path)->toEndWith('.jpg');
});
