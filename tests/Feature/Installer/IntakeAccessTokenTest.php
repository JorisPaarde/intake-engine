<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

test('access tokens are unique per intake', function () {
    $user = User::factory()->create();
    $versionId = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion()->id;

    $first = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $versionId,
    ]);

    $second = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $versionId,
    ]);

    expect($first->access_token)->not->toBe($second->access_token)
        ->and(strlen($first->access_token))->toBe(64);
});

test('installer can revoke customer access', function () {
    $user = User::factory()->create();
    $versionId = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion()->id;

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $versionId,
        'status' => IntakeStatus::Sent,
    ]);

    $this->actingAs($user)
        ->post(route('intakes.revoke', $intake))
        ->assertRedirect(route('intakes.show', $intake));

    $intake->refresh();

    expect($intake->token_revoked_at)->not->toBeNull()
        ->and($intake->status)->toBe(IntakeStatus::Cancelled)
        ->and($intake->isTokenValid())->toBeFalse();
});

test('installer can regenerate customer access token', function () {
    $user = User::factory()->create();
    $versionId = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion()->id;

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $versionId,
    ]);

    $original = $intake->access_token;

    $this->actingAs($user)
        ->post(route('intakes.regenerate-token', $intake))
        ->assertRedirect(route('intakes.show', $intake));

    $intake->refresh();

    expect($intake->access_token)->not->toBe($original)
        ->and($intake->token_revoked_at)->toBeNull()
        ->and($intake->isTokenValid())->toBeTrue();
});

test('expired or cancelled tokens are not customer-accessible', function () {
    $user = User::factory()->create();
    $versionId = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion()->id;

    $expired = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $versionId,
        'token_expires_at' => now()->subDay(),
    ]);

    $completed = Intake::factory()->completed()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $versionId,
    ]);

    expect($expired->isTokenValid())->toBeFalse()
        ->and($completed->isTokenValid())->toBeFalse();
});
