<?php

declare(strict_types=1);

use App\Domains\Intake\Mail\CustomerIntakeReminderMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Mail::fake();
    config(['intake.reminder.days' => 3]);
});

function makeStalledIntake(array $overrides = []): Intake
{
    $user = User::factory()->create();
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();

    return Intake::factory()->create(array_merge([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_name' => 'Stilliggende Klant',
        'customer_email' => 'stalling@example.com',
        'created_at' => now()->subDays(4),
        'reminder_sent_at' => null,
        'is_demo' => false,
    ], $overrides));
}

test('send-reminders mails stalled intakes once and records activity', function () {
    $intake = makeStalledIntake();

    Artisan::call('intakes:send-reminders');

    Mail::assertSent(CustomerIntakeReminderMail::class, function (CustomerIntakeReminderMail $mail) use ($intake): bool {
        return $mail->hasTo('stalling@example.com')
            && $mail->intake->is($intake)
            && str_contains($mail->envelope()->subject, 'Herinnering');
    });

    $intake->refresh();

    expect($intake->reminder_sent_at)->not->toBeNull();

    $event = IntakeActivityEvent::query()
        ->where('intake_id', $intake->id)
        ->where('event', 'customer_reminder_mailed')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->properties['reminder_days'])->toBe(3);

    $encoded = json_encode($event->properties);
    expect($encoded)->not->toContain($intake->access_token)
        ->and($encoded)->not->toContain('/o/');

    Mail::fake();
    Artisan::call('intakes:send-reminders');
    Mail::assertNothingSent();
});

test('send-reminders skips revoked expired completed demo and not-due intakes', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $user = User::factory()->create();

    makeStalledIntake([
        'customer_email' => 'revoked@example.com',
        'status' => IntakeStatus::Cancelled,
        'token_revoked_at' => now(),
    ]);

    makeStalledIntake([
        'customer_email' => 'expired@example.com',
        'token_expires_at' => now()->subDay(),
    ]);

    makeStalledIntake([
        'customer_email' => 'completed@example.com',
        'status' => IntakeStatus::Completed,
        'completed_at' => now(),
    ]);

    Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $version->id,
        'customer_email' => 'demo@demo.invalid',
        'is_demo' => true,
        'created_at' => now()->subDays(10),
        'status' => IntakeStatus::Sent,
    ]);

    makeStalledIntake([
        'customer_email' => 'fresh@example.com',
        'created_at' => now()->subDay(),
    ]);

    Artisan::call('intakes:send-reminders');

    Mail::assertNothingSent();
});

test('send-reminders skips when mailer is log to avoid tokens in logs', function () {
    config(['mail.default' => 'log']);

    $intake = makeStalledIntake();

    Artisan::call('intakes:send-reminders');

    Mail::assertNothingSent();
    expect($intake->fresh()->reminder_sent_at)->toBeNull();
});
