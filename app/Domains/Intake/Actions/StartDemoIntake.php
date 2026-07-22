<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StartDemoIntake
{
    public function __construct(
        private readonly CreateIntake $createIntake,
    ) {}

    public function handle(): Intake
    {
        if (! (bool) config('intake.demo.enabled', true)) {
            throw ValidationException::withMessages([
                'demo' => 'Demo is uitgeschakeld in deze omgeving.',
            ]);
        }

        $creator = $this->resolveDemoUser();
        $suffix = Str::lower((string) Str::ulid());

        return $this->createIntake->handle($creator, [
            'template_key' => 'airco',
            'customer_name' => 'Demo Aanvrager',
            'customer_email' => 'demo+'.$suffix.'@demo.invalid',
            'customer_phone' => null,
            'address_line' => 'Voorbeeldstraat 1',
            'address_postal_code' => '1234AB',
            'address_city' => 'Amsterdam',
            'internal_note' => 'Automatische demo-intake — geen echte offerte.',
            'is_demo' => true,
            'token_ttl_hours' => (int) config('intake.demo.ttl_hours', 12),
        ]);
    }

    private function resolveDemoUser(): User
    {
        $email = (string) config('intake.demo.user_email', 'demo@intake-engine.invalid');

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Demo Installateur',
                'password' => Str::password(32),
                'email_verified_at' => now(),
            ],
        );

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }
}
