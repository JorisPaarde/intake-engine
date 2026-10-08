<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Support\PhotoUploadLimits;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => false,
        'ai.dossier.enabled' => false,
        'intake.uploads.max_kilobytes' => 8192,
        'intake.uploads.hard_max_bytes' => 15 * 1024 * 1024,
    ]);
});

function createPhotoLimitSurvey(User $user): array
{
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Foto Limiet',
        'customer_email' => 'fotolimiet@example.com',
        'address_line' => 'Testlaan 10',
        'address_postal_code' => '1000AA',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);

    $room = app(AircoSurveyService::class)->createRoom($intake, $user, [
        'name' => 'Woonkamer',
        'use_type' => 'living',
    ]);

    return [$intake, $room];
}

test('installer storeEvidence accepteert één foto via photo[]', function () {
    $user = User::factory()->create();
    [$intake, $room] = createPhotoLimitSurvey($user);

    $this->actingAs($user)
        ->post(route('intakes.workspace.photos.store', [$intake, $room->subject]), [
            'photo' => [UploadedFile::fake()->image('wand.jpg', 1200, 900)],
        ])
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHas('status');

    expect($intake->uploads()->count())->toBe(1);
});

test('installer storeEvidence accepteert meerdere foto\'s in één request', function () {
    $user = User::factory()->create();
    [$intake, $room] = createPhotoLimitSurvey($user);

    $this->actingAs($user)
        ->post(route('intakes.workspace.photos.store', [$intake, $room->subject]), [
            'photo' => [
                UploadedFile::fake()->image('een.jpg', 800, 600),
                UploadedFile::fake()->image('twee.jpg', 800, 600),
            ],
        ])
        ->assertRedirect(route('intakes.workspace', $intake));

    expect($intake->uploads()->count())->toBe(2)
        ->and((string) session('status'))->toContain('2 foto');
});

test('installer storeEvidence geeft Nederlandse melding bij te groot bestand', function () {
    $user = User::factory()->create();
    [$intake, $room] = createPhotoLimitSurvey($user);

    $tooLargeKb = (int) ceil(PhotoUploadLimits::hardMaxBytes() / 1024) + 100;
    $tooLarge = UploadedFile::fake()->create('huge.jpg', $tooLargeKb, 'image/jpeg');

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.photos.store', [$intake, $room->subject]), [
            'photo' => [$tooLarge],
        ])
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHasErrors('photo');

    $message = session('errors')->first('photo');
    expect($message)->toBe(PhotoUploadLimits::tooLargeMessage())
        ->and($message)->not->toContain('kilobytes')
        ->and($message)->not->toContain('field');
});

test('installer storeEvidence weigert niet-afbeelding met Nederlandse melding', function () {
    $user = User::factory()->create();
    [$intake, $room] = createPhotoLimitSurvey($user);

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.photos.store', [$intake, $room->subject]), [
            'photo' => [UploadedFile::fake()->create('readme.txt', 2, 'text/plain')],
        ])
        ->assertRedirect(route('intakes.workspace', $intake))
        ->assertSessionHasErrors('photo');

    expect(session('errors')->first('photo'))->toContain('geen foto');
});

test('installer downloadfilename gebruikt .jpg na PNG-conversie', function () {
    $user = User::factory()->create();
    [$intake, $room] = createPhotoLimitSurvey($user);

    $this->actingAs($user)
        ->post(route('intakes.workspace.photos.store', [$intake, $room->subject]), [
            'photo' => UploadedFile::fake()->image('unitplek.png', 400, 300),
        ])
        ->assertRedirect();

    /** @var IntakeUpload $upload */
    $upload = $intake->uploads()->sole();
    expect($upload->mime_type)->toBe('image/jpeg')
        ->and($upload->downloadFilename())->toBe('unitplek.jpg');

    $response = $this->actingAs($user)
        ->get(route('installer.uploads.show', [$intake, $upload]));

    $response->assertOk();
    $disposition = (string) $response->headers->get('content-disposition');
    expect($disposition)->toContain('unitplek.jpg')
        ->and($disposition)->not->toContain('.png');
});
