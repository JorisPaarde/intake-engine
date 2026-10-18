<?php

declare(strict_types=1);

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

function n99ReportHtml(bool $withArea): string
{
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $intake = Intake::factory()->create([
        'created_by' => User::factory()->create()->id,
        'intake_template_version_id' => $version->id,
        'status' => IntakeStatus::Sent,
        'customer_email' => 'n99-size@example.com',
    ]);
    $save = app(SaveIntakeAnswer::class);
    $save->handle($intake, 'cooling_heating', null, ['value' => 'cooling']);
    $save->handle($intake, 'indoor_unit_count', null, ['number' => 1]);
    $save->handle($intake, 'room_name', 'room-1', ['text' => 'Slaapkamer']);
    $save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom']);
    $save->handle($intake, 'room_size_indication', 'room-1', ['value' => 'small'], PrefillSources::AI_PHOTO);
    if ($withArea) {
        $save->handle($intake, 'room_area_m2', 'room-1', ['number' => 12], PrefillSources::DERIVED_LXW);
    }

    return app(GenerateIntakeReportHtml::class)->handle(
        $intake->fresh(),
        $intake->templateVersion()->with(['sections.questions.options'])->firstOrFail(),
    );
}

test('N99: geen grootteklasse naast bekende m² in het rapport', function () {
    expect(n99ReportHtml(true))->not->toContain('Klein (tot ca. 12 m²)');
});

test('N99: zonder m² blijft de grootteklasse in het rapport', function () {
    expect(n99ReportHtml(false))->toContain('Klein (tot ca. 12 m²)');
});
