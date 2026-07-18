<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\AI\Jobs\SummarizeIntakeJob;
use App\Domains\Intake\Jobs\GenerateIntakePdfJob;
use App\Domains\Intake\Models\GeneratedReport;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Services\CompletenessChecker;
use App\Domains\Intake\Services\GenerateIntakeReportHtml;
use App\Enums\AttentionPointSource;
use App\Enums\IntakeStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompleteIntake
{
    public function __construct(
        private readonly CompletenessChecker $completenessChecker,
        private readonly GenerateIntakeReportHtml $generateIntakeReportHtml,
    ) {}

    public function handle(Intake $intake): Intake
    {
        if (! in_array($intake->status, [IntakeStatus::Sent, IntakeStatus::InProgress], true)) {
            throw ValidationException::withMessages([
                'intake' => 'Deze opname kan niet meer worden afgerond.',
            ]);
        }

        $version = $intake->templateVersion()
            ->with(['sections.questions.options', 'sections.questions.rules', 'template'])
            ->firstOrFail();

        $check = $this->completenessChecker->check($intake, $version);

        if (! $check['is_complete']) {
            throw ValidationException::withMessages([
                'completeness' => 'Nog niet alles is ingevuld. Controleer de ontbrekende onderdelen.',
            ]);
        }

        $completed = DB::transaction(function () use ($intake, $version, $check): Intake {
            $snapshot = [
                'is_complete' => true,
                'missing' => [],
                'attention_points' => $check['attention_points'],
                'checked_at' => now()->toIso8601String(),
                'template_version' => $version->version,
                'template_key' => $version->template?->key,
            ];

            $html = $this->generateIntakeReportHtml->handle(
                $intake,
                $version,
                $check['attention_points'],
            );

            $intake->update([
                'status' => IntakeStatus::Completed,
                'completed_at' => now(),
                'progress_percent' => 100,
                'completeness_snapshot' => $snapshot,
            ]);

            IntakeAttentionPoint::query()
                ->where('intake_id', $intake->id)
                ->where('source', AttentionPointSource::System)
                ->delete();

            foreach ($check['attention_points'] as $point) {
                IntakeAttentionPoint::query()->create([
                    'intake_id' => $intake->id,
                    'source' => AttentionPointSource::System,
                    'code' => $point['code'],
                    'label' => $point['label'],
                    'is_resolved' => false,
                ]);
            }

            GeneratedReport::query()->updateOrCreate(
                ['intake_id' => $intake->id],
                [
                    'html' => $html,
                    'meta' => [
                        'attention_point_codes' => array_column($check['attention_points'], 'code'),
                        'template_version' => $version->version,
                    ],
                    'generated_at' => now(),
                ],
            );

            IntakeActivityEvent::query()->create([
                'intake_id' => $intake->id,
                'actor_type' => 'customer',
                'actor_id' => null,
                'event' => 'intake_completed',
                'properties' => [
                    'attention_points' => array_column($check['attention_points'], 'code'),
                ],
                'created_at' => now(),
            ]);

            return $intake->fresh(['report', 'attentionPoints']) ?? $intake;
        });

        if (! $completed->is_demo) {
            SummarizeIntakeJob::dispatch($completed->id);
            GenerateIntakePdfJob::dispatch($completed->id);
            app(SendInstallerIntakeCompleted::class)->handle($completed);
        }

        return $completed;
    }
}
