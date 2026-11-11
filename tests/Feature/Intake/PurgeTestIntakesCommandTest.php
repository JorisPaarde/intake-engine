<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeUpload;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('filesystems.media', 'local');
});

/**
 * @return array{company: Company, user: User}
 */
function purgeTestCompany(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->create();

    return ['company' => $company, 'user' => $user];
}

test('intakes:purge-test weigert production', function () {
    $previous = app()->environment();
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        $this->artisan('intakes:purge-test', ['--email-domain' => 'example.com'])
            ->assertFailed()
            ->expectsOutputToContain('niet beschikbaar in production');
    } finally {
        app()->detectEnvironment(static fn () => $previous);
    }
});

test('intakes:purge-test dry-run toont ids zonder e-mail of naam', function () {
    $ctx = purgeTestCompany();
    $intake = Intake::factory()->create([
        'company_id' => $ctx['company']->id,
        'created_by' => $ctx['user']->id,
        'customer_name' => 'Geheim Naam',
        'customer_email' => 'tester@example.com',
        'is_demo' => false,
    ]);

    $this->artisan('intakes:purge-test', ['--email-domain' => 'example.com'])
        ->assertSuccessful()
        ->expectsOutputToContain('Gevonden: 1')
        ->expectsOutputToContain((string) $intake->id)
        ->expectsOutputToContain('Dry-run')
        ->doesntExpectOutputToContain('tester@example.com')
        ->doesntExpectOutputToContain('Geheim Naam');

    expect(Intake::query()->whereKey($intake->id)->exists())->toBeTrue();
});

test('intakes:purge-test --confirm hard-deletes matching intakes and media', function () {
    $ctx = purgeTestCompany();
    $keep = Intake::factory()->create([
        'company_id' => $ctx['company']->id,
        'created_by' => $ctx['user']->id,
        'customer_email' => 'blijft@anderdomein.nl',
        'is_demo' => false,
    ]);
    $demo = Intake::factory()->create([
        'company_id' => $ctx['company']->id,
        'created_by' => $ctx['user']->id,
        'customer_email' => 'demo@example.com',
        'is_demo' => true,
    ]);
    $target = Intake::factory()->create([
        'company_id' => $ctx['company']->id,
        'created_by' => $ctx['user']->id,
        'customer_email' => 'weg@example.com',
        'is_demo' => false,
    ]);

    $path = 'intakes/'.$target->uuid.'/photos/test.jpg';
    Storage::disk('local')->put($path, 'bytes');
    IntakeUpload::query()->create([
        'intake_id' => $target->id,
        'question_key' => 'fusebox_photo',
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 5,
        'checksum' => hash('sha256', 'bytes'),
        'sort_order' => 0,
    ]);

    $this->artisan('intakes:purge-test', [
        '--email-domain' => 'example.com',
        '--confirm' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('Hard verwijderd: 1');

    expect(Intake::withTrashed()->whereKey($target->id)->exists())->toBeFalse()
        ->and(Intake::query()->whereKey($keep->id)->exists())->toBeTrue()
        ->and(Intake::query()->whereKey($demo->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

test('intakes:purge-test weigert ongeldig email-domain', function () {
    $this->artisan('intakes:purge-test', ['--email-domain' => '*.example.com'])
        ->assertFailed()
        ->expectsOutputToContain('Ongeldig --email-domain');
});
