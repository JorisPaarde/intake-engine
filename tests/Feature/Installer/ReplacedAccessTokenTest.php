<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateCustomerContributionRequest;
use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\RegenerateIntakeAccessToken;
use App\Domains\Intake\Exceptions\CustomerLinkUnavailableException;
use App\Domains\Intake\Models\IntakeReplacedAccessToken;
use App\Domains\Intake\Services\ResolveIntakeByAccessToken;
use App\Enums\ContributionMode;
use App\Enums\FollowUpItemType;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    $this->withoutVite();
});

test('regenerated token makes the old customer link return 410 with replaced copy', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Customer,
        'customer_name' => 'Token Test',
        'customer_email' => 'token@example.com',
        'address_line' => 'Test 1',
        'address_postal_code' => '1234AB',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    $oldToken = $intake->access_token;

    app(RegenerateIntakeAccessToken::class)->handle($intake, $user);
    $intake->refresh();

    expect($intake->access_token)->not->toBe($oldToken)
        ->and(IntakeReplacedAccessToken::query()
            ->where('token_hash', IntakeReplacedAccessToken::hashToken($oldToken))
            ->exists())->toBeTrue();

    $this->get(route('customer.intake.show', ['token' => $oldToken]))
        ->assertStatus(410)
        ->assertSee('Deze link werkt niet meer')
        ->assertSee('Je installateur heeft je een nieuwere link gestuurd')
        ->assertDontSee('Deed je een demo');

    // Current token still works (wizard page — also needs withoutVite).
    $this->get(route('customer.intake.show', ['token' => $intake->access_token]))
        ->assertOk();

    // Resolve path used by uploads/company-logo middleware also sees "replaced".
    expect(fn () => app(ResolveIntakeByAccessToken::class)->handle($oldToken))
        ->toThrow(CustomerLinkUnavailableException::class);

    // Unknown token stays 404.
    $this->get(route('customer.intake.show', ['token' => str_repeat('a', 64)]))
        ->assertNotFound();

    expect(fn () => app(ResolveIntakeByAccessToken::class)->handle(str_repeat('b', 64)))
        ->toThrow(NotFoundHttpException::class);
});

test('contribution request records the previous token as replaced', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'Ronde Test',
        'customer_email' => 'ronde@example.com',
        'address_line' => 'Test 2',
        'address_postal_code' => '1234AB',
        'address_house_number' => 2,
        'address_city' => 'Amsterdam',
    ]);

    $previousToken = $intake->access_token;

    app(CreateCustomerContributionRequest::class)->handle($intake, $user, [[
        'type' => FollowUpItemType::Text->value,
        'prompt' => 'Meet de hoogte van de zolder.',
        'decision_area_key' => 'capacity',
    ]]);

    expect($intake->fresh()->access_token)->not->toBe($previousToken)
        ->and(IntakeReplacedAccessToken::query()
            ->where('token_hash', IntakeReplacedAccessToken::hashToken($previousToken))
            ->exists())->toBeTrue();

    $this->get(route('customer.intake.show', ['token' => $previousToken]))
        ->assertStatus(410)
        ->assertSee('nieuwere link gestuurd');
});
