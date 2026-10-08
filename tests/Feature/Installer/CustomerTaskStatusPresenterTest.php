<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Support\CustomerConsentPresenter;
use App\Domains\Intake\Support\CustomerTaskStatusPresenter;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

test('new intake without customer action shows nog niet gestart on dashboard and show', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Customer->value,
        'customer_name' => 'Status Nieuw',
        'customer_email' => 'status-nieuw@example.com',
        'address_line' => 'Test 1',
        'address_postal_code' => '1234AB',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    // Prefill may set progress_percent > 0; installer status must ignore that.
    $intake->forceFill(['progress_percent' => 8])->save();

    $status = app(CustomerTaskStatusPresenter::class)->present($intake->fresh(['answers', 'followUpRounds.items']));

    expect($status['label'])->toBe('Nog niet gestart')
        ->and($status['percent'])->toBe(0);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Nog niet gestart')
        ->assertDontSee('8% beantwoord');

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Klanttaak: Nog niet gestart')
        ->assertSee('Toestemming klant: niet gegeven');
});

test('active follow-up round shows received counts and active link', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer->value,
        'customer_name' => 'Status Ronde',
        'customer_email' => 'status-ronde@example.com',
        'address_line' => 'Test 2',
        'address_postal_code' => '1234AB',
        'address_house_number' => 2,
        'address_city' => 'Amsterdam',
    ]);

    app(CreateCustomerContributionRequest::class)->handle($intake, $user, [
        [
            'type' => FollowUpItemType::Text->value,
            'prompt' => 'Meet de hoogte.',
            'decision_area_key' => 'capacity',
        ],
        [
            'type' => FollowUpItemType::Photo->value,
            'prompt' => 'Maak een meterkastfoto.',
            'decision_area_key' => 'power',
        ],
    ]);

    $intake = $intake->fresh(['answers', 'followUpRounds.items']);
    $status = app(CustomerTaskStatusPresenter::class)->present($intake);

    expect($status['label'])->toBe('Ronde 1: 0 van 2 ontvangen')
        ->and($status['link_active'])->toBeTrue();

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Klanttaak: Ronde 1: 0 van 2 ontvangen')
        ->assertSee('Actieve klantlink', false);
});

test('consent presenter formats given timestamp', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Customer->value,
        'customer_name' => 'Consent',
        'customer_email' => 'consent@example.com',
        'address_line' => 'Test 3',
        'address_postal_code' => '1234AB',
        'address_house_number' => 3,
        'address_city' => 'Amsterdam',
    ]);

    $answeredAt = now()->setTimezone(config('app.timezone'))->setTime(10, 12);
    app(SaveIntakeAnswer::class)->handle($intake, 'privacy_consent', null, ['bool' => true]);
    $intake->answers()->where('question_key', 'privacy_consent')->update(['answered_at' => $answeredAt]);

    $consent = app(CustomerConsentPresenter::class)->present($intake->fresh('answers'));

    expect($consent['given'])->toBeTrue()
        ->and($consent['label'])->toContain('Toestemming klant: gegeven op')
        ->and($consent['label'])->toContain('10:12');

    $intake->forceFill(['status' => IntakeStatus::Completed, 'completed_at' => now()])->save();

    $this->actingAs($user)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Toestemming klant: gegeven op', false);
});
