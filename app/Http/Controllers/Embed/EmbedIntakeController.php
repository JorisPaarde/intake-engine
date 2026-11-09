<?php

declare(strict_types=1);

namespace App\Http\Controllers\Embed;

use App\Domains\Intake\Actions\StartEmbedIntake;
use App\Domains\Intake\Services\EmbedAllowedOriginValidator;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmbedIntakeRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EmbedIntakeController extends Controller
{
    public function __construct(
        private readonly EmbedAllowedOriginValidator $originValidator,
    ) {}

    public function show(string $public_key): Response
    {
        $company = $this->resolveEmbedCompany($public_key);

        return $this->formResponse($company, $public_key);
    }

    public function store(
        StoreEmbedIntakeRequest $request,
        string $public_key,
        StartEmbedIntake $startEmbedIntake,
    ): RedirectResponse|Response {
        $company = $this->resolveEmbedCompany($public_key);

        if ($request->filled('website')) {
            // Honeypot: pretend success without creating an intake (ProductInterest-patroon).
            return redirect()->to(url('/'), 303);
        }

        if (! $this->originValidator->requestHostIsAllowed($request, $company)) {
            abort(403, 'Dit formulier mag alleen vanaf de toegestane website worden gebruikt.');
        }

        $refererHost = $this->originValidator->hostFromRequest($request);

        try {
            $result = $startEmbedIntake->handle(
                $company,
                $request->safe()->only([
                    'customer_name',
                    'customer_email',
                    'customer_phone',
                    'address_postal_code',
                    'address_house_number',
                    'address_house_number_addition',
                ]),
                $refererHost,
            );
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 429) {
                return $this->formResponse(
                    $company,
                    $public_key,
                    status: 429,
                    errorMessage: $exception->getMessage(),
                );
            }

            throw $exception;
        }

        return redirect()->away($result['intake']->customerUrl(), 303);
    }

    private function resolveEmbedCompany(string $publicKey): Company
    {
        $company = Company::query()
            ->where('embed_public_key', $publicKey)
            ->first();

        if ($company === null || ! $company->embed_enabled || ! $company->hasEmbedPublicKey()) {
            abort(404);
        }

        // Geen users → geen created_by mogelijk; behandel als onbeschikbaar.
        if (! $company->users()->exists()) {
            abort(404);
        }

        return $company;
    }

    private function formResponse(
        Company $company,
        string $publicKey,
        int $status = 200,
        ?string $errorMessage = null,
    ): Response {
        $html = view('embed.form', [
            'company' => $company,
            'publicKey' => $publicKey,
            'errorMessage' => $errorMessage,
        ])->render();

        $ancestors = implode(' ', $this->originValidator->frameAncestorsSources($company));

        return response($html, $status)
            ->header('Content-Security-Policy', 'frame-ancestors '.$ancestors)
            ->header('Cache-Control', 'no-store');
    }
}
