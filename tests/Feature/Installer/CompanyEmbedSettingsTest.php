<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\CompanyEmbedSettings;
use App\Domains\Intake\Services\EmbedIframeSnippet;
use App\Enums\IntakeSource;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(IntakeTemplateSeeder::class);
});

test('guests cannot open company embed settings', function () {
    $this->get(route('company.settings.edit'))->assertRedirect(route('login'));
    $this->post(route('company.settings.embed.key.generate'))->assertRedirect(route('login'));
    $this->post(route('company.settings.embed.key.rotate'))->assertRedirect(route('login'));
    $this->patch(route('company.settings.embed.update'))->assertRedirect(route('login'));
});

test('installer can generate an embed public key for own company only', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->withEmbedKey('other-company-key-aaaaaaaaaaaa')->create();
    $user = User::factory()->for($company)->create();

    $this->actingAs($user)
        ->get(route('company.settings.edit'))
        ->assertOk()
        ->assertSee('Formulier op je website')
        ->assertSee('Sleutel maken')
        ->assertDontSee('other-company-key-aaaaaaaaaaaa', false);

    $this->actingAs($user)
        ->post(route('company.settings.embed.key.generate'))
        ->assertRedirect(route('company.settings.edit'))
        ->assertSessionHas('status');

    $company->refresh();

    expect($company->embed_public_key)->toBeString()
        ->and($company->embed_public_key)->not->toBe('')
        ->and($company->embed_public_key)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and($company->embed_public_key)->not->toBe('other-company-key-aaaaaaaaaaaa')
        ->and($other->fresh()->embed_public_key)->toBe('other-company-key-aaaaaaaaaaaa');

    $this->actingAs($user)
        ->get(route('company.settings.edit'))
        ->assertOk()
        ->assertSee($company->embed_public_key, false)
        ->assertSee('/embed/'.$company->embed_public_key, false)
        ->assertSee('Snippet voor je website')
        ->assertSee('intake-engine-embed', false)
        ->assertDontSee('height="720"', false);
});

test('generating twice without rotate is rejected', function () {
    $company = Company::factory()->withEmbedKey('existing-key-bbbbbbbbbbbbbbbb')->create([
        'embed_enabled' => false,
    ]);
    $user = User::factory()->for($company)->create();

    $this->actingAs($user)
        ->from(route('company.settings.edit'))
        ->post(route('company.settings.embed.key.generate'))
        ->assertRedirect(route('company.settings.edit'))
        ->assertSessionHasErrors('embed_public_key');

    expect($company->fresh()->embed_public_key)->toBe('existing-key-bbbbbbbbbbbbbbbb');
});

test('installer can rotate embed public key', function () {
    $company = Company::factory()->withEmbedKey('old-key-cccccccccccccccccccc')->create();
    $user = User::factory()->for($company)->create();
    $oldKey = $company->embed_public_key;

    $this->actingAs($user)
        ->post(route('company.settings.embed.key.rotate'))
        ->assertRedirect(route('company.settings.edit'))
        ->assertSessionHas('status');

    $company->refresh();

    expect($company->embed_public_key)->toBeString()
        ->and($company->embed_public_key)->not->toBe($oldKey)
        ->and($company->embed_public_key)->toMatch('/^[A-Za-z0-9_-]+$/');
});

test('rotate without existing key is rejected', function () {
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->create();

    $this->actingAs($user)
        ->from(route('company.settings.edit'))
        ->post(route('company.settings.embed.key.rotate'))
        ->assertRedirect(route('company.settings.edit'))
        ->assertSessionHasErrors('embed_public_key');
});

test('installer can update embed domains daily limit and toggle', function () {
    $company = Company::factory()->withEmbedKey()->create([
        'embed_enabled' => false,
        'embed_daily_limit' => 20,
        'embed_allowed_origins' => [],
    ]);
    $user = User::factory()->for($company)->create();

    $this->actingAs($user)
        ->patch(route('company.settings.embed.update'), [
            'embed_enabled' => '1',
            'embed_daily_limit' => 35,
            'embed_allowed_origins' => "www.installateur.nl\ninstallateur.nl",
        ])
        ->assertRedirect(route('company.settings.edit'))
        ->assertSessionHas('status');

    $company->refresh();

    expect($company->embed_enabled)->toBeTrue()
        ->and($company->embed_daily_limit)->toBe(35)
        ->and($company->embedAllowedOrigins())->toBe(['www.installateur.nl', 'installateur.nl']);
});

test('embed origin validation rejects wildcards paths schemes and ports', function (string $invalid) {
    $company = Company::factory()->withEmbedKey()->create();
    $user = User::factory()->for($company)->create();

    $this->actingAs($user)
        ->from(route('company.settings.edit'))
        ->patch(route('company.settings.embed.update'), [
            'embed_enabled' => '1',
            'embed_daily_limit' => 20,
            'embed_allowed_origins' => $invalid,
        ])
        ->assertRedirect(route('company.settings.edit'))
        ->assertSessionHasErrors();

    expect($company->fresh()->embedAllowedOrigins())->toBe(['www.example.nl']);
})->with([
    '*.installateur.nl',
    'https://installateur.nl',
    'installateur.nl/pad',
    'installateur.nl:443',
    '192.168.1.10',
    'localhost',
]);

test('enabling embed without a key is rejected', function () {
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->create();

    $this->actingAs($user)
        ->from(route('company.settings.edit'))
        ->patch(route('company.settings.embed.update'), [
            'embed_enabled' => '1',
            'embed_daily_limit' => 20,
            'embed_allowed_origins' => 'www.installateur.nl',
        ])
        ->assertRedirect(route('company.settings.edit'))
        ->assertSessionHasErrors('embed_enabled');

    expect($company->fresh()->embed_enabled)->toBeFalse();
});

test('embed settings only change the authenticated users company', function () {
    $company = Company::factory()->withEmbedKey('own-key-dddddddddddddddddddd')->create([
        'embed_daily_limit' => 20,
        'embed_allowed_origins' => ['www.eigen.nl'],
    ]);
    $other = Company::factory()->withEmbedKey('foreign-key-eeeeeeeeeeeeeeeeee')->create([
        'embed_daily_limit' => 20,
        'embed_allowed_origins' => ['www.ander.nl'],
        'embed_enabled' => true,
    ]);
    $user = User::factory()->for($company)->create();

    $this->actingAs($user)
        ->patch(route('company.settings.embed.update'), [
            'embed_enabled' => '1',
            'embed_daily_limit' => 12,
            'embed_allowed_origins' => 'www.nieuw.nl',
        ])
        ->assertRedirect(route('company.settings.edit'));

    expect($company->fresh()->embed_daily_limit)->toBe(12)
        ->and($company->fresh()->embedAllowedOrigins())->toBe(['www.nieuw.nl'])
        ->and($other->fresh()->embed_daily_limit)->toBe(20)
        ->and($other->fresh()->embedAllowedOrigins())->toBe(['www.ander.nl'])
        ->and($other->fresh()->embed_public_key)->toBe('foreign-key-eeeeeeeeeeeeeeeeee');
});

test('iframe snippet includes auto-resize postMessage listener with origin check', function () {
    $company = Company::factory()->withEmbedKey('snippet-key-ffffffffffffffffff')->create();
    $snippet = app(CompanyEmbedSettings::class)->iframeSnippet($company);

    expect($snippet)->toBeString()
        ->and($snippet)->toContain('/embed/snippet-key-ffffffffffffffffff')
        ->and($snippet)->toContain('intake-engine-embed')
        ->and($snippet)->toContain('addEventListener("message"')
        ->and($snippet)->toContain('event.origin!==expectedOrigin')
        ->and($snippet)->toContain('data.type!=="resize"')
        ->and($snippet)->not->toContain('height="720"')
        ->and($snippet)->toContain('height="480"');

    expect(EmbedIframeSnippet::MESSAGE_SOURCE)->toBe('intake-engine-embed')
        ->and(EmbedIframeSnippet::MESSAGE_TYPE_RESIZE)->toBe('resize');
});

test('intakes source defaults to installer for existing creates', function () {
    $company = Company::factory()->create();
    $user = User::factory()->for($company)->create();

    $intake = Intake::factory()->create([
        'company_id' => $company->id,
        'created_by' => $user->id,
    ]);

    expect($intake->fresh()->source)->toBe(IntakeSource::Installer)
        ->and(Schema::hasColumn('intakes', 'source'))->toBeTrue()
        ->and(Schema::hasColumns('companies', [
            'embed_public_key',
            'embed_allowed_origins',
            'embed_enabled',
            'embed_daily_limit',
        ]))->toBeTrue();
});
