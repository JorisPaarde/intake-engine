<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\DB;

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

test('dashboard uses withExists for customer action instead of loading all answers', function () {
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    foreach (range(1, 3) as $n) {
        Intake::factory()->create([
            'created_by' => $user->id,
            'company_id' => $user->company_id,
            'intake_template_version_id' => $version->id,
            'customer_name' => "Dashboard N+1 {$n}",
            'customer_email' => "dashboard-n1-{$n}@example.com",
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    $queries = collect(DB::getQueryLog())->pluck('query')->map(
        static fn (string $sql): string => strtolower($sql),
    );

    $fullAnswerLoads = $queries->filter(
        static fn (string $sql): bool => str_contains($sql, 'intake_answers')
            && str_contains($sql, 'select *')
            && ! str_contains($sql, 'exists'),
    );

    expect($fullAnswerLoads)->toHaveCount(0);

    $mainWithExists = $queries->first(
        static fn (string $sql): bool => str_contains($sql, 'from')
            && str_contains($sql, 'intakes')
            && str_contains($sql, 'exists')
            && str_contains($sql, 'intake_answers'),
    );

    expect($mainWithExists)->not->toBeNull();
});

test('guest cannot view the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('dashboard mobile cards expose status and open link without horizontal scroll', function () {
    $this->withoutVite();
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    Intake::factory()->create([
        'created_by' => $user->id,
        'company_id' => $user->company_id,
        'intake_template_version_id' => $version->id,
        'customer_name' => 'Mobiel Zichtbaar',
        'customer_email' => 'mobiel@example.com',
        'status' => IntakeStatus::Completed,
        'completed_at' => now(),
    ]);

    $html = $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-testid="dashboard-intake-cards"', false)
        ->assertSee('data-testid="dashboard-status-badge"', false)
        ->assertSee('data-testid="dashboard-open-link"', false)
        ->assertSee('data-testid="dashboard-intake-table"', false)
        ->getContent();

    expect($html)->toContain('Mobiel Zichtbaar')
        ->and($html)->toContain('Openen')
        ->and(substr_count($html, 'data-testid="dashboard-open-link"'))->toBe(1);
});
