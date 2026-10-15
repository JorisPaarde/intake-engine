<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\ContributionTask;
use App\Domains\Intake\Models\Intake;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
    Mail::fake();
    config(['ai.provider' => 'fake', 'ai.dossier.enabled' => false]);
});

function photoSubtypeIntake(User $user): Intake
{
    return app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer->value,
        'customer_name' => 'Subtype Meta',
        'customer_email' => 'subtype-meta@example.com',
        'address_line' => 'Test 9',
        'address_postal_code' => '1234AB',
        'address_house_number' => 9,
        'address_city' => 'Amsterdam',
    ]);
}

test('ask-customer-button posts only whitelisted photo_task_subtype', function () {
    $user = User::factory()->create();
    $intake = photoSubtypeIntake($user);

    $html = Blade::render(
        '<x-ask-customer-button :intake="$intake" :ask="$ask" />',
        [
            'intake' => $intake,
            'ask' => [
                'type' => FollowUpItemType::Photo->value,
                'prompt' => 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen, bijvoorbeeld langs de gevel of vanaf de meterkast.',
                'decision_area_key' => 'power',
                'meta' => PhotoSubject::photoTaskMetaForSubtype('power_route'),
            ],
        ],
    );

    expect($html)->toContain('name="photo_task_subtype"')
        ->and($html)->toContain('value="power_route"')
        ->and($html)->not->toContain('accepted_photo_subjects')
        ->and($html)->not->toContain('expected_photo_subject');
});

test('POST prepare → store persists power_route and fusebox meta on ContributionTask', function (string $subtype) {
    $user = User::factory()->create();
    $intake = photoSubtypeIntake($user);
    $mapped = PhotoSubject::photoTaskMetaForSubtype($subtype);
    expect($mapped)->not->toBeNull();

    $prompt = $subtype === 'power_route'
        ? 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen, bijvoorbeeld langs de gevel of vanaf de meterkast.'
        : 'Maak een duidelijke foto van de meterkast; maak de groepenkast volledig leesbaar. De installateur beoordeelt de aansluiting.';

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.prepare', $intake), [
            'type' => FollowUpItemType::Photo->value,
            'prompt' => $prompt,
            'decision_area_key' => 'power',
            'photo_task_subtype' => $subtype,
        ])
        ->assertRedirect(route('intakes.workspace', $intake).'#demo-customer-task')
        ->assertSessionHas('customer_task_drafts.0.photo_task_subtype', $subtype);

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.store', $intake), [
            'contribution_items' => [[
                'type' => FollowUpItemType::Photo->value,
                'prompt' => $prompt,
                'decision_area_key' => 'power',
                'photo_task_subtype' => $subtype,
                // Browser must not be able to force subjects:
                'meta' => [
                    'accepted_photo_subjects' => ['room'],
                    'expected_photo_subject' => 'room',
                ],
            ]],
        ])
        ->assertRedirect();

    $task = ContributionTask::query()->where('intake_id', $intake->id)->latest('id')->first();
    expect($task)->not->toBeNull()
        ->and($task->meta['photo_task_subtype'] ?? null)->toBe($subtype)
        ->and($task->meta['expected_photo_subject'] ?? null)->toBe($mapped['expected_photo_subject'])
        ->and($task->meta['accepted_photo_subjects'] ?? null)->toBe($mapped['accepted_photo_subjects'])
        ->and($task->meta['accepted_photo_subjects'] ?? [])->not->toContain('room');

    expect(PhotoSubject::acceptedSubjectsForTask($task))->toBe(
        PhotoSubject::acceptedSubjectsForSubtype($subtype),
    );
})->with([
    'power_route',
    'fusebox',
]);

test('prepare rejects unknown photo_task_subtype', function () {
    $user = User::factory()->create();
    $intake = photoSubtypeIntake($user);

    $this->actingAs($user)
        ->from(route('intakes.workspace', $intake))
        ->post(route('intakes.workspace.tasks.prepare', $intake), [
            'type' => FollowUpItemType::Photo->value,
            'prompt' => 'Maak een foto.',
            'decision_area_key' => 'power',
            'photo_task_subtype' => 'not_a_real_subtype',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('photo_task_subtype');
});

test('POST prepare power_route then store with other area rejects incompatible subtype', function () {
    $user = User::factory()->create();
    $intake = photoSubtypeIntake($user);
    $prompt = 'Maak een foto van de plek waar de stroomkabel naar de buitenunit kan lopen, bijvoorbeeld langs de gevel of vanaf de meterkast.';

    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.prepare', $intake), [
            'type' => FollowUpItemType::Photo->value,
            'prompt' => $prompt,
            'decision_area_key' => 'power',
            'photo_task_subtype' => 'power_route',
        ])
        ->assertRedirect(route('intakes.workspace', $intake).'#demo-customer-task')
        ->assertSessionHas('customer_task_drafts.0.photo_task_subtype', 'power_route');

    // Installer changed “onderdeel” in the draft form away from power without clearing subtype.
    $this->actingAs($user)
        ->post(route('intakes.workspace.tasks.store', $intake), [
            'contribution_items' => [[
                'type' => FollowUpItemType::Photo->value,
                'prompt' => $prompt,
                'decision_area_key' => 'capacity',
                'photo_task_subtype' => 'power_route',
            ]],
        ])
        ->assertSessionHasErrors([
            'contribution_items.0.photo_task_subtype' => 'Dit fototype past niet bij het gekozen onderdeel.',
        ]);

    expect(ContributionTask::query()->where('intake_id', $intake->id)->count())->toBe(0);
});
