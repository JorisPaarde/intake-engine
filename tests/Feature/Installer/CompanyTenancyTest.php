<?php

declare(strict_types=1);

use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Models\IntakeUpload;
use App\Enums\IntakeStatus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

test('installer dashboard is scoped to the users company and still shares company intakes', function () {
    $company = Company::factory()->create(['name' => 'Eigen Installatiebedrijf']);
    $otherCompany = Company::factory()->create(['name' => 'Ander Installatiebedrijf']);
    $owner = User::factory()->for($company)->create();
    $colleague = User::factory()->for($company)->create();
    $otherUser = User::factory()->for($otherCompany)->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    Intake::factory()->create([
        'company_id' => $company->id,
        'created_by' => $owner->id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Gedeelde Klant',
        'customer_email' => 'gedeeld@example.com',
    ]);
    Intake::factory()->create([
        'company_id' => $otherCompany->id,
        'created_by' => $otherUser->id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Verborgen Klant',
        'customer_email' => 'verborgen@example.com',
    ]);

    $this->actingAs($colleague)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Gedeelde Klant')
        ->assertDontSee('Verborgen Klant');
});

test('installer actions reject intakes outside the users company', function () {
    Storage::fake('local');

    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $user = User::factory()->for($company)->create();
    $otherUser = User::factory()->for($otherCompany)->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $foreign = Intake::factory()->create([
        'company_id' => $otherCompany->id,
        'created_by' => $otherUser->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
    ]);
    $upload = IntakeUpload::query()->create([
        'intake_id' => $foreign->id,
        'question_key' => 'room_photo',
        'disk' => 'local',
        'path' => 'intakes/foreign/private.jpg',
        'original_filename' => 'private.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 12,
    ]);
    Storage::disk('local')->put($upload->path, 'private-image');
    GeneratedReport::query()->create([
        'intake_id' => $foreign->id,
        'html' => '<h1>Geheim rapport</h1>',
        'generated_at' => now(),
    ]);

    $this->actingAs($user)->get(route('intakes.show', $foreign))->assertForbidden();
    $this->actingAs($user)->post(route('intakes.revoke', $foreign))->assertForbidden();
    $this->actingAs($user)->post(route('intakes.review', $foreign), [
        'decision' => 'prepare_quote',
    ])->assertForbidden();
    $this->actingAs($user)->get(route('installer.uploads.show', [$foreign, $upload]))->assertForbidden();
    $this->actingAs($user)->get(route('intakes.report', $foreign))->assertForbidden();
});

test('same company colleague can mutate an intake created by another user', function () {
    $company = Company::factory()->create();
    $owner = User::factory()->for($company)->create();
    $colleague = User::factory()->for($company)->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'company_id' => $company->id,
        'created_by' => $owner->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
    ]);

    $this->actingAs($colleague)
        ->post(route('intakes.revoke', $intake))
        ->assertRedirect(route('intakes.show', $intake));

    expect($intake->refresh()->status)->toBe(IntakeStatus::Cancelled)
        ->and($intake->token_revoked_at)->not->toBeNull();
});

test('metrics are scoped to the users company', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $user = User::factory()->for($company)->create();
    $otherUser = User::factory()->for($otherCompany)->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    Intake::factory()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Meetbare Klant',
    ]);
    Intake::factory()->create([
        'company_id' => $otherCompany->id,
        'created_by' => $otherUser->id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Vreemde Klant',
    ]);

    $this->actingAs($user)
        ->get(route('metrics'))
        ->assertOk()
        ->assertSee('1 opname')
        ->assertDontSee('Vreemde Klant');
});
