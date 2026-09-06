<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\EvaluateRequestIntent;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeActivityEvent;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeUpload;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Staging-only dry-run van openingszin → lokale parser → catalogus-AI (BL-104).
 * Geen duurzame side effects; deelt EvaluateRequestIntent met het productiepad.
 */
final class DevAiInputTestController extends Controller
{
    public function show(Request $request): View
    {
        $evaluation = $request->session()->get('dev_ai_input_evaluation');

        return view('dev.ai-input-test', [
            'evaluation' => $evaluation,
            'requestReason' => old('request_reason', ''),
            'maxLength' => max(10, (int) config('devadmin.ai_input_test.max_length', 2000)),
            'example' => 'Ik wil drie airco’s voor al mijn slaapkamers en één voor mijn woonkamer om te koelen',
        ]);
    }

    public function evaluate(
        Request $request,
        EvaluateRequestIntent $evaluateRequestIntent,
    ): RedirectResponse {
        $maxLength = max(10, (int) config('devadmin.ai_input_test.max_length', 2000));

        $validated = $request->validate([
            'request_reason' => ['required', 'string', 'min:10', 'max:'.$maxLength],
        ], [
            'request_reason.required' => 'Vul een proeftekst in.',
            'request_reason.min' => 'De proeftekst moet minimaal 10 tekens zijn.',
            'request_reason.max' => "De proeftekst mag maximaal {$maxLength} tekens zijn.",
        ]);

        $before = $this->sideEffectSnapshot();

        try {
            $evaluation = $evaluateRequestIntent->preview((string) $validated['request_reason']);
        } catch (RuntimeException $exception) {
            return back()
                ->withInput(['request_reason' => $validated['request_reason']])
                ->withErrors(['request_reason' => 'Evaluatie mislukt: configuratie of template ontbreekt.']);
        } catch (Throwable) {
            return back()
                ->withInput(['request_reason' => $validated['request_reason']])
                ->withErrors(['request_reason' => 'Evaluatie mislukt. De proeftekst is niet gelogd.']);
        }

        $after = $this->sideEffectSnapshot();
        if ($before !== $after) {
            // Soft assert in UI: dry-run mag nooit muteren.
            return back()
                ->withInput(['request_reason' => $validated['request_reason']])
                ->withErrors(['request_reason' => 'Dry-run stopte: er zouden side effects zijn ontstaan.']);
        }

        // Alleen geclassificeerd resultaat in de sessie — nooit de vrije proeftekst bewaren.
        $request->session()->flash('dev_ai_input_evaluation', $evaluation->toArray());

        return redirect()->route('dev.ai-input-test');
    }

    /**
     * @return array{intakes: int, answers: int, ai_runs: int, events: int, uploads: int}
     */
    private function sideEffectSnapshot(): array
    {
        return [
            'intakes' => Intake::withTrashed()->count(),
            'answers' => IntakeAnswer::query()->count(),
            'ai_runs' => AiRun::query()->count(),
            'events' => IntakeActivityEvent::query()->count(),
            'uploads' => IntakeUpload::query()->count(),
        ];
    }
}
