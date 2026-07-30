<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProductInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:1500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_name' => 'bedrijfsnaam',
            'contact_name' => 'naam',
            'email' => 'e-mailadres',
            'phone' => 'telefoonnummer',
            'message' => 'toelichting',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_name.required' => 'Vul uw bedrijfsnaam in.',
            'company_name.max' => 'De bedrijfsnaam mag maximaal 120 tekens bevatten.',
            'contact_name.required' => 'Vul uw naam in.',
            'contact_name.max' => 'De naam mag maximaal 120 tekens bevatten.',
            'email.required' => 'Vul uw e-mailadres in.',
            'email.email' => 'Vul een geldig e-mailadres in.',
            'email.max' => 'Het e-mailadres mag maximaal 254 tekens bevatten.',
            'phone.max' => 'Het telefoonnummer mag maximaal 40 tekens bevatten.',
            'message.max' => 'De toelichting mag maximaal 1.500 tekens bevatten.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['company_name', 'contact_name', 'email', 'phone', 'message'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $value = trim($value);
                $normalized[$key] = $value === '' ? null : $value;
            }
        }

        $this->merge($normalized);
    }

    protected function getRedirectUrl(): string
    {
        return route('home').'#interesse';
    }
}
