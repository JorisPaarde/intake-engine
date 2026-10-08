<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

test('ask-customer-button puts caller classes on the button and keeps form inline', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer->value,
        'customer_name' => 'Ask Button',
        'customer_email' => 'ask-button@example.com',
        'address_line' => 'Test 0',
        'address_postal_code' => '1234AB',
        'address_house_number' => 10,
        'address_city' => 'Amsterdam',
    ]);

    $html = Blade::render(
        '<x-ask-customer-button :intake="$intake" :ask="$ask" label="Vraag nieuwe foto" class="inline-flex min-h-10 w-full items-center rounded-lg border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-800 hover:bg-indigo-50" />',
        [
            'intake' => $intake,
            'ask' => [
                'type' => FollowUpItemType::Photo->value,
                'prompt' => 'Maak een nieuwe foto van de meterkast.',
                'decision_area_key' => 'power',
            ],
        ],
    );

    expect($html)->toMatch('/<form[^>]*class="inline"/')
        ->and($html)->toContain('border-indigo-200')
        ->and($html)->toContain('text-indigo-800')
        ->and($html)->toContain('w-full')
        ->and($html)->toContain('Vraag nieuwe foto');

    // Caller classes must land on the button, not only the form.
    expect($html)->toMatch('/<button[^>]*border-indigo-200[^>]*>/')
        ->and($html)->toMatch('/<button[^>]*w-full[^>]*>/');
});

test('prepare contribution rejects GET and accepts POST', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer->value,
        'customer_name' => 'Prepare Method',
        'customer_email' => 'prepare@example.com',
        'address_line' => 'Test 1',
        'address_postal_code' => '1234AB',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    $this->actingAs($user)
        ->get(route('intakes.workspace.tasks.prepare', [
            'intake' => $intake,
            'type' => FollowUpItemType::Text->value,
            'prompt' => 'Meet de hoogte.',
        ]))
        ->assertStatus(405);

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.prepare', $intake), [
            'type' => FollowUpItemType::Text->value,
            'prompt' => 'Meet de hoogte.',
            'decision_area_key' => 'capacity',
        ])
        ->assertRedirect(route('intakes.workspace', $intake).'#demo-customer-task')
        ->assertSessionHas('customer_task_drafts.0.prompt', 'Meet de hoogte.');
});

test('aerial image route authorizes tenant and returns jpeg headers', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer->value,
        'customer_name' => 'Luchtfoto',
        'customer_email' => 'lucht@example.com',
        'address_line' => 'Test 2',
        'address_postal_code' => '1234AB',
        'address_house_number' => 2,
        'address_city' => 'Amsterdam',
    ]);

    $path = 'aerial/'.$intake->id.'/test.jpg';
    Storage::disk('local')->put($path, base64_decode(
        '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGfAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUCf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Bf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Bf//Z',
        true,
    ) ?: 'not-a-jpeg');

    // Minimal valid-enough JPEG bytes for streaming.
    Storage::disk('local')->put($path, "\xFF\xD8\xFF\xD9");

    IntakeExternalFact::query()->create([
        'intake_id' => $intake->id,
        'fact_key' => 'aerial_image',
        'label' => 'Luchtfoto',
        'value' => [
            'media_disk' => 'local',
            'media_path' => $path,
            'mime_type' => 'image/jpeg',
        ],
        'source' => 'PDOK Luchtfoto RGB',
        'source_url' => null,
        'confidence' => 'high',
        'captured_at' => now(),
    ]);

    $aerial = $this->actingAs($user)
        ->get(route('intakes.aerial.show', $intake))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $aerialCache = (string) $aerial->headers->get('Cache-Control');
    expect($aerialCache)->toContain('private')
        ->and($aerialCache)->toContain('max-age=300');

    $other = User::factory()->create();
    $this->actingAs($other)
        ->get(route('intakes.aerial.show', $intake))
        ->assertForbidden();
});

test('installer upload response uses private no-store cache header', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer->value,
        'customer_name' => 'Cache',
        'customer_email' => 'cache@example.com',
        'address_line' => 'Test 3',
        'address_postal_code' => '1234AB',
        'address_house_number' => 3,
        'address_city' => 'Amsterdam',
    ]);

    $path = 'uploads/'.$intake->id.'/photo.jpg';
    Storage::disk('local')->put($path, "\xFF\xD8\xFF\xD9");

    $upload = IntakeUpload::query()->create([
        'intake_id' => $intake->id,
        'question_key' => 'room_overview_photo',
        'section_instance_key' => null,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 4,
    ]);

    $uploadResponse = $this->actingAs($user)
        ->get(route('installer.uploads.show', [$intake, $upload]))
        ->assertOk();

    $uploadCache = (string) $uploadResponse->headers->get('Cache-Control');
    expect($uploadCache)->toContain('private')
        ->and($uploadCache)->toContain('no-store');
});
