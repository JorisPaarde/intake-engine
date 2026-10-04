<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Support\PhotoOverridePolicy;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

function darkUpload(): UploadedFile
{
    $img = imagecreatetruecolor(800, 800);
    imagefill($img, 0, 0, imagecolorallocate($img, 8, 8, 8));
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent('dark.jpg', $bytes);
}

test('a dark photo shows override feedback and still stores the upload', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Foto Klant',
        'customer_email' => 'foto@example.com',
    ]);
    app(SaveIntakeAnswer::class)->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $intake->update([
        'current_section_key' => 'rooms',
        'current_question_key' => 'room_photos',
        'current_section_instance_key' => 'room-1',
    ]);

    $composite = 'room-1__room_photos';

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('photoFiles.'.$composite, darkUpload())
        ->call('assessPendingUploads');

    $hint = $component->get('photoHint');

    expect($hint[$composite] ?? null)->toContain('donker')
        ->toContain('nieuwe foto met meer licht')
        ->and($component->get('showMissing'))->toBeFalse();

    $upload = $intake->uploads()->where('question_key', 'room_photos')->firstOrFail();
    expect($upload)->not->toBeNull()
        ->and(PhotoOverridePolicy::needsOverride($upload))->toBeTrue()
        ->and(PhotoOverridePolicy::OVERRIDE_MESSAGE_WIZARD)->toContain('Toch doorgaan');
});
