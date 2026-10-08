<?php

declare(strict_types=1);

use App\Domains\AI\Clients\FakeAiClient;
use App\Domains\AI\Models\AiRun;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Domains\Intake\Services\AircoSurveyService;
use App\Domains\Intake\Support\PhotoUploadLimits;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
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

test('installer storeEvidence houdt geslaagde foto\'s bij als latere foto faalt', function () {
    $user = User::factory()->create();
    [$intake, $room] = createPhotoLimitSurvey($user);

    $corruptPath = tempnam(sys_get_temp_dir(), 'kapot-').'.jpg';
    // Valid JPEG SOI/EOI so mime checks pass; Imagick/GD cannot decode pixels.
    file_put_contents($corruptPath, "\xFF\xD8\xFF\xD9");
    $corrupt = new UploadedFile($corruptPath, 'kapot.jpg', 'image/jpeg', null, true);

    try {
        $this->actingAs($user)
            ->from(route('intakes.workspace', $intake))
            ->post(route('intakes.workspace.photos.store', [$intake, $room->subject]), [
                'photo' => [
                    UploadedFile::fake()->image('goed.jpg', 800, 600),
                    $corrupt,
                ],
            ])
            ->assertRedirect(route('intakes.workspace', $intake))
            ->assertSessionHas('status');

        $status = (string) session('status');
        expect($intake->uploads()->count())->toBe(1)
            ->and($status)->toContain('1 van 2 foto')
            ->and($status)->toContain('kapot.jpg')
            ->and($status)->toContain('kon niet automatisch worden verwerkt');
    } finally {
        @unlink($corruptPath);
    }
});

test('installer storeEvidence toont te-groot-melding bij UPLOAD_ERR_INI_SIZE', function () {
    $user = User::factory()->create();
    [$intake, $room] = createPhotoLimitSurvey($user);

    $path = tempnam(sys_get_temp_dir(), 'ini-size-').'.jpg';
    file_put_contents($path, 'x');
    $tooLarge = new UploadedFile($path, 'huge.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

    try {
        $this->actingAs($user)
            ->from(route('intakes.workspace', $intake))
            ->post(route('intakes.workspace.photos.store', [$intake, $room->subject]), [
                'photo' => [$tooLarge],
            ])
            ->assertRedirect(route('intakes.workspace', $intake))
            ->assertSessionHasErrors('photo');

        expect(session('errors')->first('photo'))->toBe(PhotoUploadLimits::tooLargeMessage());
    } finally {
        @unlink($path);
    }
});

test('PostTooLargeException op installer foto-route geeft Nederlandse 413-pagina', function () {
    $user = User::factory()->create();
    [$intake, $room] = createPhotoLimitSurvey($user);

    $workspaceUrl = route('intakes.workspace', $intake);
    $url = route('intakes.workspace.photos.store', [$intake, $room->subject], absolute: false);
    // No session — production ValidatePostSize path has none either.
    $request = Request::create($url, 'POST', server: [
        'HTTP_REFERER' => $workspaceUrl,
    ]);
    $this->app->instance('request', $request);

    $response = app(ExceptionHandler::class)->render(
        $request,
        new PostTooLargeException,
    );

    $content = (string) $response->getContent();
    expect($response->getStatusCode())->toBe(413)
        ->and($content)->toContain('data-testid="post-too-large-back"')
        ->and($content)->toContain('te groot voor één upload')
        ->and($content)->toContain('Terug naar opname')
        ->and($content)->toContain(parse_url($workspaceUrl, PHP_URL_PATH) ?: $workspaceUrl);
});

test('PostTooLargeException met vreemde Referer valt terug op home', function () {
    $user = User::factory()->create();
    [$intake, $room] = createPhotoLimitSurvey($user);

    $url = route('intakes.workspace.photos.store', [$intake, $room->subject], absolute: false);
    $request = Request::create($url, 'POST', server: [
        'HTTP_HOST' => 'staging.intake-engine.nl',
        'HTTP_REFERER' => 'https://evil.example/phishing',
    ]);
    $this->app->instance('request', $request);

    $response = app(ExceptionHandler::class)->render(
        $request,
        new PostTooLargeException,
    );

    $content = (string) $response->getContent();
    expect($response->getStatusCode())->toBe(413)
        ->and($content)->toContain('data-testid="post-too-large-back"')
        ->and($content)->not->toContain('evil.example')
        ->and($content)->toContain('href="'.e(url('/')).'"');
});

test('PostTooLargeException op andere route blijft standaard (geen photo-pagina)', function () {
    $request = Request::create('/dashboard', 'POST');
    $this->app->instance('request', $request);

    expect($request->path())->toBe('dashboard')
        ->and($request->is('intakes/*/opname/subjects/*/photos'))->toBeFalse();

    $response = app(ExceptionHandler::class)->render(
        $request,
        new PostTooLargeException,
    );

    $content = (string) $response->getContent();
    // Default Laravel/Ignition 413 — not our Dutch workspace photo page
    // (Ignition may embed bootstrap/app.php source that mentions the Dutch copy).
    expect($response->getStatusCode())->toBe(413)
        ->and($content)->not->toContain('data-testid="post-too-large-back"')
        ->and($content)->toContain('PostTooLargeException');
});

test('installer multi-foto runt sync AI alleen voor de eerste opgeslagen foto', function () {
    config([
        'ai.provider' => 'fake',
        'ai.photo_inference.enabled' => true,
    ]);
    FakeAiClient::reset();
    FakeAiClient::alwaysReturn([
        'observations' => [[
            'text' => 'Muur lijkt bereikbaar vanaf de grond.',
            'impact' => 'installation',
            'confidence' => 0.9,
        ]],
    ]);

    $user = User::factory()->create();
    [$intake, $room] = createPhotoLimitSurvey($user);

    $this->actingAs($user)
        ->post(route('intakes.workspace.photos.store', [$intake, $room->subject]), [
            'photo' => [
                UploadedFile::fake()->image('een.jpg', 800, 600),
                UploadedFile::fake()->image('twee.jpg', 800, 600),
            ],
        ])
        ->assertRedirect();

    expect($intake->uploads()->count())->toBe(2)
        ->and(AiRun::query()->where('intake_id', $intake->id)->count())->toBe(1);
});

test('installer photo form listens on document and scopes prep by input id', function () {
    $blade = (string) file_get_contents(resource_path('views/installer/intakes/_subject-tools.blade.php'));

    expect($blade)->toContain('intake:photo-prep-start.document')
        ->and($blade)->toContain('matchesScope(event)')
        ->and($blade)->toContain('inputId')
        ->and($blade)->toContain('x-bind:disabled="prepBusy"')
        ->and($blade)->toContain('event.isTrusted')
        ->and($blade)->toContain('this.prepError + \' \' + message')
        ->and($blade)->not->toContain('@error(\'photo\')')
        ->and($blade)->toContain(':key="index"');
});

test('assessment poll lives on wizards not only on photo-upload-control', function () {
    $control = (string) file_get_contents(resource_path('views/components/customer/photo-upload-control.blade.php'));
    $intake = (string) file_get_contents(resource_path('views/livewire/customer/intake-wizard.blade.php'));
    $followUp = (string) file_get_contents(resource_path('views/livewire/customer/follow-up-wizard.blade.php'));

    expect($control)->not->toContain('wire:poll')
        ->and($control)->toContain('PhotoAssessmentSoftTimeout::seconds()')
        ->and($intake)->toContain('data-testid="assessment-poll"')
        ->and($intake)->toContain('pollPendingAssessments(@json($composite))')
        ->and($intake)->toContain('wire:key="assessment-poll-{{ $composite }}-{{ $assessmentPollInterval }}"')
        ->and($intake)->not->toContain("['assessing', 'failed']")
        ->and($followUp)->toContain('data-testid="assessment-poll"')
        ->and($followUp)->toContain('pollPendingAssessments(@json($followUpComposite))')
        ->and($followUp)->toContain('wire:key="assessment-poll-{{ $followUpComposite }}-{{ $assessmentPollInterval }}"')
        ->and($followUp)->not->toContain("['assessing', 'failed']");
});
