<?php

declare(strict_types=1);

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\IntakeStatus;
use App\Livewire\Customer\IntakeWizard;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(IntakeTemplateSeeder::class));

test('N86: klantwizard toont geen Vorige op de eerste stap, wel daarna', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'access_token' => str_repeat('b', 48).bin2hex(random_bytes(8)),
        'token_expires_at' => now()->addDays(7),
        'customer_email' => 'n86@example.com',
    ]);

    $component = Livewire::test(IntakeWizard::class, ['token' => $intake->access_token])
        ->set('stepIndex', 0);
    $component->assertDontSeeHtml('wire:click="previous"');

    $component->set('stepIndex', 1)->assertSeeHtml('wire:click="previous"');
});
