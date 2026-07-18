<?php

declare(strict_types=1);

use App\Domains\Intake\Mail\CustomerIntakeLinkMail;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Mail::fake();
});

test('creating an intake mails the customer link and records a safe activity event', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Mail Klant',
        'customer_email' => 'mail.klant@example.com',
        'address_line' => 'Testlaan 1',
    ])->assertRedirect();

    $intake = Intake::query()->where('customer_email', 'mail.klant@example.com')->firstOrFail();

    Mail::assertSent(CustomerIntakeLinkMail::class, function (CustomerIntakeLinkMail $mail) use ($intake): bool {
        return $mail->hasTo('mail.klant@example.com')
            && $mail->intake->is($intake)
            && str_contains($mail->envelope()->subject, 'digitale opname');
    });

    $event = IntakeActivityEvent::query()
        ->where('intake_id', $intake->id)
        ->where('event', 'customer_link_mailed')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->actor_type)->toBe('user')
        ->and($event->actor_id)->toBe($user->id)
        ->and($event->properties)->toBe(['mailer' => 'array']);

    $encoded = json_encode($event->properties);
    expect($encoded)->not->toContain($intake->access_token)
        ->and($encoded)->not->toContain('/o/');
});

test('customer link mail is skipped when mailer is log to avoid tokens in logs', function () {
    config(['mail.default' => 'log']);

    $user = User::factory()->create();

    $this->actingAs($user)->post(route('intakes.store'), [
        'template_key' => 'airco',
        'customer_name' => 'Log Klant',
        'customer_email' => 'log.klant@example.com',
        'address_line' => 'Testlaan 2',
    ])->assertRedirect();

    expect(session('status'))->toBeString()->toContain('nog niet geconfigureerd');

    Mail::assertNothingSent();

    $intake = Intake::query()->where('customer_email', 'log.klant@example.com')->firstOrFail();

    expect(
        IntakeActivityEvent::query()
            ->where('intake_id', $intake->id)
            ->where('event', 'customer_link_mailed')
            ->exists()
    )->toBeFalse();
});

test('installer can resend the customer link mail', function () {
    $user = User::factory()->create();
    $versionId = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion()->id;

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $versionId,
        'customer_email' => 'resend@example.com',
        'status' => IntakeStatus::Sent,
    ]);

    $this->actingAs($user)
        ->post(route('intakes.send-link', $intake))
        ->assertRedirect(route('intakes.show', $intake))
        ->assertSessionHas('status');

    expect(session('status'))->toContain('opnieuw gemaild');

    Mail::assertSent(CustomerIntakeLinkMail::class, fn (CustomerIntakeLinkMail $mail): bool => $mail->hasTo('resend@example.com'));
});

test('regenerating a token also mails the new customer link', function () {
    $user = User::factory()->create();
    $versionId = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion()->id;

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $versionId,
        'customer_email' => 'regen@example.com',
        'status' => IntakeStatus::Sent,
    ]);

    $this->actingAs($user)
        ->post(route('intakes.regenerate-token', $intake))
        ->assertRedirect(route('intakes.show', $intake));

    expect(session('status'))->toContain('gemaild');

    Mail::assertSent(CustomerIntakeLinkMail::class, function (CustomerIntakeLinkMail $mail) use ($intake): bool {
        $intake->refresh();

        return $mail->hasTo('regen@example.com')
            && $mail->intake->access_token === $intake->access_token;
    });
});

test('resend is refused for revoked customer links', function () {
    $user = User::factory()->create();
    $versionId = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion()->id;

    $intake = Intake::factory()->create([
        'created_by' => $user->id,
        'intake_template_version_id' => $versionId,
        'customer_email' => 'revoked@example.com',
        'status' => IntakeStatus::Cancelled,
        'token_revoked_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('intakes.send-link', $intake))
        ->assertRedirect(route('intakes.show', $intake));

    expect(session('status'))->toContain('niet meer geldig');

    Mail::assertNothingSent();
});

test('demo intakes never receive a customer link mail', function () {
    config([
        'intake.demo.enabled' => true,
        'intake.demo.user_email' => 'demo@intake-engine.test',
    ]);

    $this->post(route('demo.start'));

    Mail::assertNothingSent();

    $intake = Intake::query()->where('is_demo', true)->firstOrFail();

    expect(
        IntakeActivityEvent::query()
            ->where('intake_id', $intake->id)
            ->where('event', 'customer_link_mailed')
            ->exists()
    )->toBeFalse();
});
