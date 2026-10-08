<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\CreateIntake;
use App\Enums\ContributionMode;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    $this->withoutVite();
});

test('dutch branded pages render for common http errors', function (int $status, string $needle) {
    if ($status === 405) {
        Route::post('/__test/http-error-405-post-only', fn () => response('ok'));

        $this->get('/__test/http-error-405-post-only')
            ->assertStatus(405)
            ->assertSee('Actie niet toegestaan', false)
            ->assertDontSee('Oops! An Error Occurred');

        return;
    }

    Route::get('/__test/http-error-'.$status, function () use ($status) {
        abort($status);
    });

    $this->get('/__test/http-error-'.$status)
        ->assertStatus($status)
        ->assertSee($needle, false)
        ->assertDontSee('Oops! An Error Occurred')
        ->assertDontSee('Server Error');
})->with([
    [403, 'Geen toegang'],
    [405, 'Actie niet toegestaan'],
    [419, 'Sessie verlopen'],
    [500, 'Er ging iets mis'],
    [503, 'Tijdelijk niet bereikbaar'],
]);

test('failed AI dossier synthesis flashes an error style message not success', function () {
    $user = User::factory()->create();
    $intake = app(CreateIntake::class)->handle($user, [
        'template_key' => 'airco',
        'workflow_mode' => ContributionMode::Installer,
        'customer_name' => 'AI Fout',
        'customer_email' => 'ai-fout@example.com',
        'address_line' => 'Testlaan 1',
        'address_postal_code' => '1000AA',
        'address_house_number' => 1,
        'address_city' => 'Amsterdam',
    ]);

    $this->actingAs($user)
        ->withSession([
            'error' => 'AI-synthese kon niet worden afgerond: providerfout; het bestaande dossier is ongewijzigd gebleven.',
        ])
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('AI-synthese kon niet worden afgerond', false)
        ->assertSee('role="alert"', false)
        ->assertSee('border-red-200 bg-red-50', false)
        ->assertDontSee('border-emerald-200 bg-emerald-50', false);
});
