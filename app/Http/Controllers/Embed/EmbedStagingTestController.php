<?php

declare(strict_types=1);

namespace App\Http\Controllers\Embed;

use App\Domains\Intake\Services\EmbedIframeSnippet;
use App\Domains\Intake\Support\EmbedStagingTest;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\View\View;

/**
 * Publieke staging/local testpagina met iframe-snippet (BL-153).
 * Production → 404. Zonder voorbereide company → 404 met korte uitleg via abort.
 */
final class EmbedStagingTestController extends Controller
{
    public function __invoke(): View
    {
        abort_unless($this->environmentAllowed(), 404);

        $company = Company::query()
            ->where('embed_public_key', EmbedStagingTest::PUBLIC_KEY)
            ->first();

        if ($company === null || ! $company->embed_enabled || ! $company->hasEmbedPublicKey()) {
            abort(404, 'Embed-testcompany ontbreekt. Draai op staging: php artisan embed:prepare-staging-test');
        }

        $publicKey = EmbedStagingTest::PUBLIC_KEY;
        $iframeSrc = url('/embed/'.$publicKey);

        return view('embed.staging-test', [
            'company' => $company,
            'publicKey' => $publicKey,
            'iframeSrc' => $iframeSrc,
            'iframeSnippet' => app(EmbedIframeSnippet::class)->forSrc($iframeSrc, [
                'id' => 'intake-embed-staging-test',
                'title' => 'Opname starten via websiteformulier',
            ]),
        ]);
    }

    private function environmentAllowed(): bool
    {
        return in_array((string) app()->environment(), ['local', 'staging', 'testing'], true);
    }
}
