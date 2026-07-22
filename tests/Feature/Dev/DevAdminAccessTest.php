<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    config(['devadmin.enabled' => true]);
});

test('guest is redirected to login', function () {
    $this->get('/dev')->assertRedirect('/login');
});

test('authenticated installer can open the dev-admin when enabled', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dev')->assertOk()->assertSee('Dev-admin');
});

test('dev-admin is a hard 404 when disabled (production)', function () {
    config(['devadmin.enabled' => false]);
    $this->actingAs(User::factory()->create());

    $this->get('/dev')->assertNotFound();
    $this->get('/dev/health')->assertNotFound();
    $this->get('/dev/ai-runs')->assertNotFound();
    $this->get('/dev/activity')->assertNotFound();
    $this->get('/dev/intakes')->assertNotFound();
});

test('all dev-admin pages render for an authenticated installer', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dev/health')->assertOk();
    $this->get('/dev/ai-runs')->assertOk();
    $this->get('/dev/activity')->assertOk();
    $this->get('/dev/intakes')->assertOk();
});

test('opname-inspector shows the raw data of an intake', function () {
    $this->seed(IntakeTemplateSeeder::class);
    $this->actingAs(User::factory()->create());

    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'address_line' => 'Damrak 1',
    ]);

    $this->get(route('dev.intakes.show', $intake))
        ->assertOk()
        ->assertSee('Damrak 1')
        ->assertSee($intake->uuid);
});
