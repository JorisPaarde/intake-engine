<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;

test('registration atomically creates a company and assigns the user', function () {
    $response = $this->post('/register', [
        'company_name' => 'Nieuwe Installateur BV',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $company = Company::query()->where('name', 'Nieuwe Installateur BV')->firstOrFail();

    expect($user->company_id)->toBe($company->id)
        ->and($company->uuid)->toHaveLength(36)
        ->and($company->slug)->toStartWith('nieuwe-installateur-bv');
});
