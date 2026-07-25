<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Intake\Models\Intake;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CompanyLogoController extends Controller
{
    public function installer(Request $request, Company $company): StreamedResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId !== null && (int) $companyId === (int) $company->id, 403);

        return $this->stream($company);
    }

    public function customer(Request $request): StreamedResponse
    {
        $intake = $request->attributes->get('customer_intake');
        abort_unless($intake instanceof Intake, 404);

        $company = $intake->company;
        abort_unless($company instanceof Company, 404);

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
