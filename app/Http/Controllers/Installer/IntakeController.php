<?php

declare(strict_types=1);

namespace App\Http\Controllers\Installer;

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Actions\RegenerateIntakeAccessToken;
use App\Domains\Intake\Actions\RevokeIntakeAccess;
use App\Domains\Intake\Actions\SubmitIntakeReview;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeQuestion;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Enums\ReviewDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Installer\StoreIntakeRequest;
use App\Http\Requests\Installer\StoreIntakeReviewRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class IntakeController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Intake::class);

        $templates = IntakeTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('installer.intakes.create', [
            'templates' => $templates,
            // BL-016: questions the installer may optionally pre-answer, per template.
            'prefillQuestionsByTemplate' => $this->prefillQuestionsByTemplate($templates),
        ]);
    }

    /**
     * @param  Collection<int, IntakeTemplate>  $templates
     * @return array<string, Collection<int, IntakeQuestion>>
     */
    private function prefillQuestionsByTemplate($templates): array
    {
        $byTemplate = [];

        foreach ($templates as $template) {
            $version = $template->latestPublishedVersion();

            if ($version === null) {
                continue;
            }

            $version->loadMissing('sections.questions.options');

            $questions = $version->sections
                ->sortBy('sort_order')
                ->flatMap(fn ($section) => $section->questions->sortBy('sort_order'))
                ->filter(fn ($question) => ($question->meta['installer_prefillable'] ?? false) === true)
                ->values();

            if ($questions->isNotEmpty()) {
                $byTemplate[$template->key] = $questions;
            }
        }

        return $byTemplate;
    }

    public function store(StoreIntakeRequest $request, CreateIntake $createIntake): RedirectResponse
    {
        $intake = $createIntake->handle($request->user(), $request->validated());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'Opname aangemaakt. De klantlink staat klaar om te kopiëren.');
    }

    public function show(Intake $intake): View
    {
        $this->authorize('view', $intake);

        $intake->load([
            'templateVersion.template',
            'creator',
            'uploads',
            'answers',
            'attentionPoints',
            'report',
            'review.reviewer',
        ]);

        return view('installer.intakes.show', [
            'intake' => $intake,
            'reviewDecisions' => collect(ReviewDecision::cases())
                ->reject(static fn (ReviewDecision $decision): bool => $decision === ReviewDecision::Pending)
                ->values(),
        ]);
    }

    public function review(
        StoreIntakeReviewRequest $request,
        Intake $intake,
        SubmitIntakeReview $submitIntakeReview,
    ): RedirectResponse {
        $submitIntakeReview->handle($intake, $request->user(), $request->validated());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'Beoordeling opgeslagen.');
    }

    public function revoke(Request $request, Intake $intake, RevokeIntakeAccess $revokeIntakeAccess): RedirectResponse
    {
        $this->authorize('revoke', $intake);

        $revokeIntakeAccess->handle($intake, $request->user());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'Klantlink ingetrokken en opname geannuleerd.');
    }

    public function regenerateToken(Request $request, Intake $intake, RegenerateIntakeAccessToken $regenerate): RedirectResponse
    {
        $this->authorize('update', $intake);

        $regenerate->handle($intake, $request->user());

        return redirect()
            ->route('intakes.show', $intake)
            ->with('status', 'Nieuwe klantlink gegenereerd.');
    }
}
