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
        private readonly EnrichIntakeAddress $enrichIntakeAddress,
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

        $intake = $this->createIntake->handle($creator, [
            'template_key' => 'airco',
            'customer_name' => 'Demo Aanvrager',
            'customer_email' => 'demo+'.$suffix.'@demo.invalid',
            'customer_phone' => null,
            'address_line' => (string) config('intake.demo.address.line', 'Damrak 1'),
            'address_postal_code' => (string) config('intake.demo.address.postal_code', '1012LG'),
            'address_city' => (string) config('intake.demo.address.city', 'Amsterdam'),
            'internal_note' => 'Automatische demo-intake — geen echte offerte.',
            'is_demo' => true,
            'token_ttl_hours' => (int) config('intake.demo.ttl_hours', 12),
        ]);

        // De demo moet dezelfde afleiding krijgen als een echte opname: zonder deze
        // aanroep draait BAG/PDOK nooit en blijft `skip_when_prefilled_by` dood.
        // Soft-fail zit in de action zelf, dus een storing blokkeert de demo niet.
        $this->enrichIntakeAddress->handle($intake);

        return $intake->fresh(['templateVersion.template']) ?? $intake;
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
