<?php

declare(strict_types=1);

namespace App\Http\Controllers\Demo;

use App\Domains\Intake\Actions\RequestDemoReportPdf;
use App\Domains\Intake\Models\Intake;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestDemoReportPdfRequest;
use Illuminate\Http\RedirectResponse;

final class RequestDemoReportPdfController extends Controller
{
    public function __invoke(
        RequestDemoReportPdfRequest $request,
        Intake $intake,
        RequestDemoReportPdf $requestDemoReportPdf,
    ): RedirectResponse {
        $this->authorize('view', $intake);
        abort_unless($intake->is_demo, 404);

        if ($request->filled('website')) {
            return redirect()
                ->back()
                ->with('status', 'Bedankt. We sturen het demorapport zodra het klaar is.');
        }

        $result = $requestDemoReportPdf->handle(
            $intake,
            $request->user(),
            (string) $request->validated('email'),
        );

        $status = $result['emailed']
            ? 'Het demorapport wordt naar '.$result['interest']->email.' gestuurd. We hebben je aanvraag ook als kennismaking genoteerd.'
            : 'Het demorapport is klaar om te downloaden. Je aanvraag is als kennismaking genoteerd'
                .(config('mail.default') === 'log' ? ' (e-mail staat in deze omgeving uit).' : '.');

        return redirect()
            ->back()
            ->withFragment('demo-pdf-request')
            ->with('status', $status);
    }
}
