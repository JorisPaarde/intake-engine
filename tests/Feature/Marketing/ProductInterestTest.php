<?php

declare(strict_types=1);

use App\Mail\ProductInterestReceivedMail;
use App\Models\ProductInterest;
use Illuminate\Support\Facades\Mail;

it('stores a valid product interest and queues the internal notification', function () {
    Mail::fake();
    config([
        'intake.interest.recipient' => 'sales@example.com',
        'intake.interest.retention_days' => 365,
        'mail.default' => 'array',
    ]);

    $response = $this->post(route('product-interest.store'), [
        'company_name' => 'Koel & Co',
        'contact_name' => 'Noor de Vries',
        'email' => 'noor@example.com',
        'phone' => '06 12345678',
        'message' => 'Wij willen de pilot met twee monteurs bekijken.',
    ]);

    $interest = ProductInterest::query()->firstOrFail();

    $response
        ->assertRedirect(route('home').'#interesse')
        ->assertSessionHas('interest_submitted', true);

    expect($interest->company_name)->toBe('Koel & Co')
        ->and($interest->contact_name)->toBe('Noor de Vries')
        ->and($interest->notification_queued_at)->not->toBeNull()
        ->and($interest->expires_at->isSameDay(now()->addDays(365)))->toBeTrue();

    Mail::assertQueued(ProductInterestReceivedMail::class, function (ProductInterestReceivedMail $mail) use ($interest) {
        return $mail->interest->is($interest)
            && $mail->hasTo('sales@example.com');
    });
});

it('still stores interest when no notification recipient is configured', function () {
    Mail::fake();
    config([
        'intake.interest.recipient' => null,
        'mail.default' => 'array',
    ]);

    $this->post(route('product-interest.store'), [
        'company_name' => 'Installatiebedrijf West',
        'contact_name' => 'Sam Peters',
        'email' => 'sam@example.com',
    ])->assertRedirect(route('home').'#interesse');

    expect(ProductInterest::query()->count())->toBe(1)
        ->and(ProductInterest::query()->first()?->notification_queued_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('does not put contact details into the log mailer', function () {
    Mail::fake();
    config([
        'intake.interest.recipient' => 'sales@example.com',
        'mail.default' => 'log',
    ]);

    $this->post(route('product-interest.store'), [
        'company_name' => 'Logvrij BV',
        'contact_name' => 'Lina Jansen',
        'email' => 'lina@example.com',
    ])->assertRedirect(route('home').'#interesse');

    expect(ProductInterest::query()->count())->toBe(1)
        ->and(ProductInterest::query()->first()?->notification_queued_at)->toBeNull();

    Mail::assertNothingQueued();
});

it('validates the public interest form without storing invalid data', function () {
    $this->from(route('home').'#interesse')
        ->post(route('product-interest.store'), [
            'company_name' => '',
            'contact_name' => '',
            'email' => 'geen-email',
            'message' => str_repeat('x', 1501),
        ])
        ->assertRedirect(route('home').'#interesse')
        ->assertSessionHasErrors(['company_name', 'contact_name', 'email', 'message']);

    expect(ProductInterest::query()->count())->toBe(0);
});

it('silently accepts the honeypot without persisting personal data', function () {
    Mail::fake();

    $this->post(route('product-interest.store'), [
        'company_name' => 'Botbedrijf',
        'contact_name' => 'Robot',
        'email' => 'robot@example.com',
        'website' => 'https://spam.example',
    ])
        ->assertRedirect(route('home').'#interesse')
        ->assertSessionHas('interest_submitted', true);

    expect(ProductInterest::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('rate limits repeated public interest submissions', function () {
    config([
        'intake.interest.recipient' => null,
        'intake.interest.throttle_per_hour' => 2,
    ]);

    foreach (['een@example.com', 'twee@example.com'] as $email) {
        $this->post(route('product-interest.store'), [
            'company_name' => 'Koelbedrijf',
            'contact_name' => 'Contactpersoon',
            'email' => $email,
        ])->assertRedirect(route('home').'#interesse');
    }

    $this->post(route('product-interest.store'), [
        'company_name' => 'Koelbedrijf',
        'contact_name' => 'Contactpersoon',
        'email' => 'drie@example.com',
    ])->assertTooManyRequests();

    expect(ProductInterest::query()->count())->toBe(2);
});

it('purges product interest after the configured retention period', function () {
    ProductInterest::query()->create([
        'company_name' => 'Verlopen BV',
        'contact_name' => 'Oude Lead',
        'email' => 'oud@example.com',
        'expires_at' => now()->subSecond(),
    ]);
    ProductInterest::query()->create([
        'company_name' => 'Actief BV',
        'contact_name' => 'Nieuwe Lead',
        'email' => 'nieuw@example.com',
        'expires_at' => now()->addDay(),
    ]);

    $this->artisan('product-interests:purge')
        ->expectsOutput('Purged 1 product interest submission(s).')
        ->assertSuccessful();

    expect(ProductInterest::query()->pluck('email')->all())->toBe(['nieuw@example.com']);
});
