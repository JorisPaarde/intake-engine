<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CompanyLogoController extends Controller
{
    public function installer(Request $request, Company $company): StreamedResponse
    {
        abort_unless((int) $request->user()?->company_id === (int) $company->id, 403);

        return $this->stream($company);
    }

    public function customer(Request $request, string $token): StreamedResponse
    {
        $intake = $request->attributes->get('customer_intake');
        abort_unless(is_object($intake) && method_exists($intake, 'company'), 404);

        /** @var Company $company */
        $company = $intake->company()->firstOrFail();

        return $this->stream($company);
    }

    private function stream(Company $company): StreamedResponse
    {
        abort_unless($company->hasLogo(), 404);

        $disk = Storage::disk((string) $company->logo_disk);
        $path = (string) $company->logo_path;

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, (string) ($company->logo_original_filename ?: 'logo'), [
            'Content-Type' => (string) $company->logo_mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
