<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake('local');
});

test('company settings update name manual color and private logo metadata', function () {
    $company = Company::factory()->create(['name' => 'Oude Naam']);
    $user = User::factory()->for($company)->create();

    $this->actingAs($user)
        ->patch(route('company.settings.update'), [
            'name' => 'Nieuwe Naam',
            'primary_color' => '#123ABC',
            'logo' => UploadedFile::fake()->image('logo.png', 120, 120)->size(256),
        ])
        ->assertRedirect(route('company.settings.edit'));

    $company->refresh();

    expect($company->name)->toBe('Nieuwe Naam')
        ->and($company->primary_color)->toBe('#123ABC')
        ->and($company->on_primary_color)->toBe('#FFFFFF')
        ->and($company->logo_disk)->toBe('local')
        ->and($company->logo_path)->toStartWith('companies/'.$company->uuid.'/branding/');

    Storage::disk('local')->assertExists($company->logo_path);
});

test('company logo validation rejects fake image content by server inspection', function () {
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->create();

    $this->actingAs($user)
        ->patch(route('company.settings.update'), [
            'name' => 'Naam',
            'primary_color' => '',
            'logo' => UploadedFile::fake()->createWithContent('logo.png', 'not an image'),
        ])
        ->assertSessionHasErrors('logo');
});

test('company logo validation rejects excessive pixel dimensions before decoding', function () {
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->create();
    $path = tempnam(sys_get_temp_dir(), 'wide-logo-').'.png';
    $image = imagecreatetruecolor(5000, 1);
    imagepng($image, $path);

    $this->actingAs($user)
        ->patch(route('company.settings.update'), [
            'name' => 'Naam',
            'primary_color' => '',
            'logo' => new UploadedFile($path, 'breed-logo.png', 'image/png', null, true),
        ])
        ->assertSessionHasErrors('logo');

    @unlink($path);
});

test('installer logo route only serves the authenticated users company logo', function () {
    $company = Company::factory()->withLogo('own-logo')->create();
    $otherCompany = Company::factory()->withLogo('foreign-logo')->create();
    $user = User::factory()->for($company)->create();

    Storage::disk('local')->put($company->logo_path, 'own-logo');
    Storage::disk('local')->put($otherCompany->logo_path, 'foreign-logo');

    $this->actingAs($user)
        ->get(route('company.logo.show', $company))
        ->assertOk()
        ->assertStreamedContent('own-logo');

    $this->actingAs($user)
        ->get(route('company.logo.show', $otherCompany))
        ->assertForbidden();
});

test('customer logo route is token bound to exactly one intake company', function () {
    $company = Company::factory()->withLogo('customer-logo')->create(['primary_color' => '#114477']);
    $otherCompany = Company::factory()->withLogo('other-logo')->create(['primary_color' => '#AA2200']);
    $user = User::factory()->for($company)->create();
    $otherUser = User::factory()->for($otherCompany)->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    $intake = Intake::factory()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
    ]);
    $otherIntake = Intake::factory()->create([
        'company_id' => $otherCompany->id,
        'created_by' => $otherUser->id,
        'intake_template_version_id' => $version->id,
    ]);

    Storage::disk('local')->put($company->logo_path, 'customer-logo');
    Storage::disk('local')->put($otherCompany->logo_path, 'other-logo');

    $this->get(route('customer.company-logo.show', ['token' => $intake->access_token]))
        ->assertOk()
        ->assertStreamedContent('customer-logo');

    $this->get(route('customer.company-logo.show', ['token' => $otherIntake->access_token]))
        ->assertOk()
        ->assertStreamedContent('other-logo');
});

test('customer wizard receives only the intake company theme tokens', function () {
    $company = Company::factory()->create(['name' => 'Eigen Merk', 'primary_color' => '#114477']);
    $otherCompany = Company::factory()->create(['name' => 'Ander Merk', 'primary_color' => '#AA2200']);
    $user = User::factory()->for($company)->create();
    $otherUser = User::factory()->for($otherCompany)->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
    ]);
    Intake::factory()->create([
        'company_id' => $otherCompany->id,
        'created_by' => $otherUser->id,
        'intake_template_version_id' => $version->id,
    ]);

    $this->get(route('customer.intake.show', $intake->access_token))
        ->assertOk()
        ->assertSee('--tenant-primary: #114477', false)
        ->assertSee('Eigen Merk')
        ->assertDontSee('#AA2200', false)
        ->assertDontSee('Ander Merk');
});
