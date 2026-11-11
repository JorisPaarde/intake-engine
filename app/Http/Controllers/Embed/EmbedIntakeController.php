<?php

declare(strict_types=1);

namespace App\Http\Controllers\Embed;

use App\Domains\Intake\Actions\StartEmbedIntake;
use App\Domains\Intake\Services\EmbedAllowedOriginValidator;
use App\Domains\Intake\Services\EmbedFormToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmbedIntakeRequest;
use App\Models\Company;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag as SupportMessageBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EmbedIntakeController extends Controller
{
    public function __construct(
        private readonly EmbedAllowedOriginValidator $originValidator,
        private readonly EmbedFormToken $formToken,
    ) {}

    public function show(string $public_key): Response
    {
        $company = $this->resolveEmbedCompany($public_key);

        return $this->formResponse($company, $public_key);
    }

    public function store(
        Request $request,
        string $public_key,
        StartEmbedIntake $startEmbedIntake,
    ): RedirectResponse|Response {
        $company = $this->resolveEmbedCompany($public_key);

        try {
            $this->formToken->assertValid($public_key, $request->input(EmbedFormToken::FIELD));
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 419) {
                return $this->formResponse(
                    $company,
                    $public_key,
                    status: 419,
                    errorMessage: $exception->getMessage(),
                );
            }

            throw $exception;
        }

        if ($request->filled('website')) {
            // Honeypot: pretend success without creating an intake (ProductInterest-patroon).
            return $this->confirmationResponse($company);
        }

        if (! $this->originValidator->requestHostIsAllowed($request, $company)) {
            abort(403, 'Dit formulier mag alleen vanaf de toegestane website worden gebruikt.');
        }

        $normalized = StoreEmbedIntakeRequest::normalize($request->all());

        if (StoreEmbedIntakeRequest::wantsAddressEdit($normalized)) {
            return $this->formResponse(
                $company,
                $public_key,
                oldInput: $normalized,
            );
        }

        $validator = Validator::make(
            $normalized,
            StoreEmbedIntakeRequest::ruleSet(),
            StoreEmbedIntakeRequest::messageSet(),
            StoreEmbedIntakeRequest::attributeLabels(),
        );

        if ($validator->fails()) {
            return $this->formResponse(
                $company,
                $public_key,
                status: 422,
                fieldErrors: $validator->errors(),
                oldInput: $normalized,
            );
        }

        /** @var array{
         *     customer_name: string,
         *     customer_email: string,
         *     customer_phone?: string|null,
         *     address_postal_code: string,
         *     address_house_number: int|string,
         *     address_house_number_addition?: string|null
         * } $data
         */
        $data = $validator->safe()->only([
            'customer_name',
            'customer_email',
            'customer_phone',
            'address_postal_code',
            'address_house_number',
            'address_house_number_addition',
        ]);

        if (! StoreEmbedIntakeRequest::addressIsConfirmed($normalized)) {
            try {
                $resolved = $startEmbedIntake->resolveAddressPreview($data);
            } catch (ValidationException $exception) {
                return $this->formResponse(
                    $company,
                    $public_key,
                    status: 422,
                    fieldErrors: $exception->validator->errors(),
                    oldInput: $normalized,
                );
            }

            return $this->addressConfirmResponse(
                $company,
                $public_key,
                $normalized,
                $resolved['summary'],
            );
        }

        $refererHost = $this->originValidator->hostFromRequest($request);

        try {
            $result = $startEmbedIntake->handle($company, $data, $refererHost);
        } catch (ValidationException $exception) {
            return $this->formResponse(
                $company,
                $public_key,
                status: 422,
                fieldErrors: $exception->validator->errors(),
                oldInput: $normalized,
            );
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 429) {
                return $this->formResponse(
                    $company,
                    $public_key,
                    status: 429,
                    errorMessage: $exception->getMessage(),
                    oldInput: $normalized,
                );
            }

            throw $exception;
        }

        if (! $result['created']) {
            // Dedup: neutrale bevestiging in iframe — geen Location /o/{token}.
            return $this->confirmationResponse($company);
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

    /**
     * @param  array<string, mixed>  $oldInput
     */
    private function formResponse(
        Company $company,
        string $publicKey,
        int $status = 200,
        ?string $errorMessage = null,
        ?MessageBag $fieldErrors = null,
        array $oldInput = [],
    ): Response {
        $html = view('embed.form', [
            'company' => $company,
            'publicKey' => $publicKey,
            'errorMessage' => $errorMessage,
            'embedFormToken' => $this->formToken->issue($publicKey),
            'oldInput' => $oldInput,
            'fieldErrors' => $fieldErrors ?? new SupportMessageBag,
        ])->render();

        return $this->framedHtml($company, $html, $status);
    }

    /**
     * @param  array<string, mixed>  $oldInput
     */
    private function addressConfirmResponse(
        Company $company,
        string $publicKey,
        array $oldInput,
        string $addressSummary,
    ): Response {
        $html = view('embed.address-confirm', [
            'company' => $company,
            'publicKey' => $publicKey,
            'embedFormToken' => $this->formToken->issue($publicKey),
            'oldInput' => $oldInput,
            'addressSummary' => $addressSummary,
        ])->render();

        return $this->framedHtml($company, $html, 200);
    }

    private function confirmationResponse(Company $company): Response
    {
        $html = view('embed.confirmation', [
            'company' => $company,
        ])->render();

        return $this->framedHtml($company, $html, 200);
    }

    private function framedHtml(Company $company, string $html, int $status): Response
    {
        $ancestors = implode(' ', $this->originValidator->frameAncestorsSources($company));

        return response($html, $status)
            ->header('Content-Security-Policy', 'frame-ancestors '.$ancestors)
            ->header('Cache-Control', 'no-store');
    }
}
