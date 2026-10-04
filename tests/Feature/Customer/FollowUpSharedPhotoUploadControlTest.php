<?php

declare(strict_types=1);

use App\Domains\AI\Jobs\AssessUploadedPhotoJob;
use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Jobs\ProcessIntakePhotoVariantsJob;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    config([
        'intake.uploads.hard_max_bytes' => 15 * 1024 * 1024,
        'intake.uploads.hard_max_megapixels' => 24,
    ]);
});

function followUpSharedControlIntake(): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::InProgress,
        'customer_name' => 'Intake 100 follow-up',
        'customer_email' => 'intake100@example.com',
        'address_line' => 'Teststraat 1',
        'address_city' => 'Utrecht',
        'access_token' => str_repeat('e', 64),
        'token_expires_at' => now()->addDays(14),
    ]);
}

/** ~2+ MB phone-sized JPEG for follow-up round 2 (installer test 4 / intake 100). */
function followUpSharedControlMeterkastJpeg(string $name = 'meterkast-2mb.jpg'): UploadedFile
{
    $width = 3024;
    $height = 4032;
    $img = imagecreatetruecolor($width, $height);
    expect($img)->not->toBeFalse();

    for ($y = 0; $y < $height; $y += 16) {
        $color = imagecolorallocate($img, ($y * 37) % 255, ($y * 17) % 255, ($y * 53) % 255);
        imagefilledrectangle($img, 0, $y, $width - 1, min($height - 1, $y + 15), $color);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'fu100');
    expect($tmp)->not->toBeFalse();
    imagejpeg($img, $tmp, 92);
    imagedestroy($img);

    $bytes = (string) file_get_contents((string) $tmp);
    @unlink((string) $tmp);

    expect(strlen($bytes))->toBeGreaterThan(200_000)
        ->and(strlen($bytes))->toBeLessThan(15 * 1024 * 1024);

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

test('follow-up wizard renders shared upload control with timing and downscale hooks', function () {
    $intake = followUpSharedControlIntake();
    app(DossierManager::class)->initialize($intake);
    $user = User::query()->findOrFail($intake->created_by);

    app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast.',
        'decision_area_key' => 'power',
    ]]);

    Livewire::test(IntakeWizard::class, ['token' => $intake->fresh()->access_token])
        ->assertSet('followUpMode', true)
        ->assertSeeHtml('data-upload-timing="1"')
        ->assertSeeHtml('data-client-downscale="1"')
        ->assertSeeHtml('data-testid="upload-retry-button"')
        ->assertSeeHtml('data-testid="upload-progress"')
        ->assertSeeHtml('wire:model="followUpPhotoFiles.')
        ->assertSeeHtml('armInactivityTimer')
        ->assertSeeHtml('armServerWaitTimer')
        ->assertSeeHtml('maken of kiezen');
});

test('follow-up round accepts large meterkast photo via followUpPhotoClientOriginals', function () {
    Queue::fake([
        AssessUploadedPhotoJob::class,
        ProcessIntakePhotoVariantsJob::class,
    ]);

    $intake = followUpSharedControlIntake();
    app(DossierManager::class)->initialize($intake);
    $user = User::query()->findOrFail($intake->created_by);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast.',
        'decision_area_key' => 'power',
    ]]);
    $item = $round->items()->firstOrFail();
    $composite = (string) $item->id;

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->fresh()->access_token])
        ->assertSet('followUpMode', true)
        ->set('followUpPhotoClientOriginals.'.$composite, [
            ['width' => 3024, 'height' => 4032],
        ])
        ->set('followUpPhotoFiles.'.$item->id, followUpSharedControlMeterkastJpeg())
        ->assertHasNoErrors('followUpPhotoFiles.'.$item->id)
        ->assertSet('uploadPhase', 'assessing')
        ->assertSet('uploadPhaseComposite', $composite);

    $item->refresh()->load('uploads');
    $upload = $item->uploads->firstOrFail();

    expect($upload->size_bytes)->toBeGreaterThan(200_000)
        ->and($component->get('followUpPhotoClientOriginals'))->not->toHaveKey($composite)
        ->and($component->get('pendingAssessUploadIds')[$composite] ?? [])->toContain($upload->id);

    Queue::assertPushed(ProcessIntakePhotoVariantsJob::class);
});

test('follow-up upload error leaves retry possible and a later valid photo works', function () {
    Queue::fake([
        AssessUploadedPhotoJob::class,
        ProcessIntakePhotoVariantsJob::class,
    ]);

    $intake = followUpSharedControlIntake();
    app(DossierManager::class)->initialize($intake);
    $user = User::query()->findOrFail($intake->created_by);

    $round = app(CreateCustomerContributionRequest::class)->handle($intake->fresh(), $user, [[
        'type' => FollowUpItemType::Photo,
        'prompt' => 'Maak een duidelijke foto van de meterkast.',
        'decision_area_key' => 'power',
    ]]);
    $item = $round->items()->firstOrFail();
    $composite = (string) $item->id;

    $oversized = UploadedFile::fake()->createWithContent(
        'te-groot.jpg',
        str_repeat('x', 15 * 1024 * 1024 + 2048),
    );

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->fresh()->access_token])
        ->assertSet('followUpMode', true)
        ->set('followUpPhotoFiles.'.$item->id, $oversized)
        ->assertHasErrors('followUpPhotoFiles.'.$item->id)
        ->assertSeeHtml('data-testid="upload-retry-button"')
        ->assertSeeHtml('x-bind:disabled="(clientUploading && ! uploadTimedOut) || prepBusy"');

    expect($item->fresh()->uploads)->toHaveCount(0);

    // Retry with a valid large meterkast photo must succeed after the error.
    $component
        ->set('followUpPhotoClientOriginals.'.$composite, [
            ['width' => 3024, 'height' => 4032],
        ])
        ->set('followUpPhotoFiles.'.$item->id, followUpSharedControlMeterkastJpeg())
        ->assertHasNoErrors('followUpPhotoFiles.'.$item->id)
        ->assertSet('uploadPhase', 'assessing');

    expect($item->fresh()->uploads)->toHaveCount(1);
});
