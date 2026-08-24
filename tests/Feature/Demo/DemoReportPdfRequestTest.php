<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\RequestDemoReportPdf;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Enums\ContributionMode;
use App\Enums\IntakeStatus;
use App\Mail\DemoReportPdfMail;
use App\Mail\ProductInterestReceivedMail;
use App\Models\ProductInterest;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
    Storage::fake((string) config('filesystems.media', 'local'));
});

/**
 * @return array{intake: Intake, user: User, session: array<string, mixed>}
 */
function demoPdfSession(): array
{
    config([
        'intake.demo.enabled' => true,
        'intake.demo.ttl_hours' => 2,
        'intake.interest.recipient' => 'info@jpwebcreation.nl',
        'mail.default' => 'array',
    ]);

    test()->post(route('demo.start'))->assertRedirect(route('dashboard'));

    $user = User::query()
        ->where('email', 'like', 'installateur+%@demo.invalid')
        ->latest('id')
        ->firstOrFail();

    $session = [
        'public_demo_mode' => true,
        'public_demo_company_id' => $user->company_id,
        'public_demo_expires_at' => now()->addHours(2)->toIso8601String(),
        'public_demo_guide_step' => 'welcome',
        'public_demo_intake_id' => null,
    ];

    test()->actingAs($user)
        ->withSession($session)
        ->post(route('intakes.store'), [
            'template_key' => 'airco',
            'workflow_mode' => ContributionMode::Customer->value,
            'customer_name' => 'Voorbeeldklant',
            'customer_email' => 'voorbeeld@demo.invalid',
            'address_line' => 'Bernadottelaan 273',
            'address_postal_code' => '2037GR',
            'address_house_number' => 273,
            'address_city' => 'Haarlem',
        ])
        ->assertRedirect();

    $intake = Intake::query()->where('is_demo', true)->where('created_by', $user->id)->firstOrFail();
    $session['public_demo_intake_id'] = $intake->id;
    $session['public_demo_guide_step'] = 'installer_start';
    $session['public_demo_path_chosen'] = 'installer';

    test()->actingAs($user)
        ->withSession($session)
        ->post(route('demo.path.choose', $intake), ['path' => 'installer']);

    return ['intake' => $intake->fresh(), 'user' => $user, 'session' => $session];
}

it('emails the demo PDF and stores a lead for info@jpwebcreation.nl', function () {
    Mail::fake();
    ['intake' => $intake, 'user' => $user, 'session' => $session] = demoPdfSession();

    $this->actingAs($user)
        ->withSession($session)
        ->post(route('demo.report-pdf', $intake), [
            'email' => 'joris@voorbeeld.nl',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $interest = ProductInterest::query()->firstOrFail();
    $intake->refresh()->load('report');

    expect($interest->email)->toBe('joris@voorbeeld.nl')
        ->and($interest->message)->toContain(RequestDemoReportPdf::LEAD_MARKER)
        ->and($interest->message)->toContain($intake->uuid)
        ->and($interest->message)->not->toContain('voorbeeld@demo.invalid')
        ->and($interest->message)->not->toContain('Voorbeeldklant')
        ->and($interest->phone)->toBeNull()
        ->and($interest->notification_queued_at)->not->toBeNull()
        ->and($intake->report?->hasPdf())->toBeTrue();

    Mail::assertQueued(ProductInterestReceivedMail::class, function (ProductInterestReceivedMail $mail) use ($interest): bool {
        return $mail->interest->is($interest)
            && $mail->hasTo('info@jpwebcreation.nl')
            && $mail->envelope()->subject === 'Demo-lead: PDF-aanvraag Digitale Opname';
    });

    Mail::assertQueued(DemoReportPdfMail::class, function (DemoReportPdfMail $mail): bool {
        return $mail->hasTo('joris@voorbeeld.nl')
            && $mail->email === 'joris@voorbeeld.nl';
    });

    expect(IntakeActivityEvent::query()
        ->where('intake_id', $intake->id)
        ->where('event', 'demo_report_pdf_requested')
        ->exists())->toBeTrue();
});

it('stores the lead but skips mail when MAIL_MAILER is log', function () {
    Mail::fake();
    ['intake' => $intake, 'user' => $user, 'session' => $session] = demoPdfSession();
    config(['mail.default' => 'log']);

    $this->actingAs($user)
        ->withSession($session)
        ->post(route('demo.report-pdf', $intake), [
            'email' => 'log@voorbeeld.nl',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(ProductInterest::query()->count())->toBe(1)
        ->and(ProductInterest::query()->first()?->notification_queued_at)->toBeNull()
        ->and($intake->fresh()->report?->hasPdf())->toBeTrue();

    Mail::assertNothingQueued();
});

it('silently accepts the honeypot without creating a lead or sending mail', function () {
    Mail::fake();
    ['intake' => $intake, 'user' => $user, 'session' => $session] = demoPdfSession();

    $this->actingAs($user)
        ->withSession($session)
        ->post(route('demo.report-pdf', $intake), [
            'email' => 'spam@voorbeeld.nl',
            'website' => 'https://spam.example',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(ProductInterest::query()->count())->toBe(0)
        ->and($intake->fresh()->report)->toBeNull();

    Mail::assertNothingQueued();
});

it('shows the PDF request form on the demo workspace and dossier page', function () {
    ['intake' => $intake, 'user' => $user, 'session' => $session] = demoPdfSession();

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('intakes.workspace', $intake))
        ->assertOk()
        ->assertSee('Wilt u het demorapport als PDF?')
        ->assertSee('demo-pdf-request', false)
        ->assertSee(route('demo.report-pdf', $intake), false)
        ->assertDontSee('info@jpwebcreation.nl');

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('intakes.show', $intake))
        ->assertOk()
        ->assertSee('Wilt u het demorapport als PDF?')
        ->assertSee('demo-pdf-request', false);
});

it('rejects PDF requests for non-demo intakes', function () {
    Mail::fake();
    $user = User::factory()->create();
    $intake = Intake::factory()->create([
        'company_id' => $user->company_id,
        'created_by' => $user->id,
        'is_demo' => false,
        'status' => IntakeStatus::InProgress,
    ]);

    $this->actingAs($user)
        ->post(route('demo.report-pdf', $intake), ['email' => 'x@example.com'])
        ->assertNotFound();

    expect(ProductInterest::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});
