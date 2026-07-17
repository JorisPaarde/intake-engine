<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

test('installer can view the dashboard with intakes', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Dashboard Klant',
        'customer_email' => 'dashboard@example.com',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Klant')
        ->assertSee('dashboard@example.com')
        ->assertSee('Verstuurd');
});

test('guest cannot view the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
