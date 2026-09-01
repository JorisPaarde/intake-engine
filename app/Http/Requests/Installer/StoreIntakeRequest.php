<?php

declare(strict_types=1);

namespace App\Http\Requests\Installer;

use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Services\PublicDemoSession;
use App\Enums\ContributionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Intake::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'address_postal_code' => ['required', 'string', 'regex:/^[1-9]\d{3}[A-Z]{2}$/'],
            'address_house_number' => ['required', 'integer', 'min:1', 'max:999999'],
            'address_house_number_addition' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s]+$/'],
            'address_line' => ['required', 'string', 'max:255'],
            'address_city' => ['required', 'string', 'max:120'],
            'address_lookup_id' => ['nullable', 'string', 'regex:/^adr-[a-f0-9]{32}$/'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'template_key' => ['required', 'string', Rule::exists('intake_templates', 'key')->where('is_active', true)],
            'workflow_mode' => ['required', Rule::enum(ContributionMode::class)],
            // BL-016: optional installer pre-answers (question_key => value). CreateIntake
            // whitelists these against the pinned version's installer_prefillable questions.
            'prefill' => ['nullable', 'array'],
            'prefill.*' => ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_name' => 'naam klant',
            'customer_email' => 'e-mailadres',
            'customer_phone' => 'telefoonnummer',
            'address_postal_code' => 'postcode',
            'address_house_number' => 'huisnummer',
            'address_house_number_addition' => 'toevoeging',
            'address_line' => 'straat en huisnummer',
            'address_city' => 'plaats',
            'address_lookup_id' => 'geselecteerd adres',
            'internal_note' => 'interne notitie',
            'template_key' => 'type opname',
            'workflow_mode' => 'manier van opnemen',
        ];
    }

    protected function prepareForValidation(): void
    {
        $rawPostalCode = $this->input('address_postal_code');
        $postalCode = is_string($rawPostalCode) && trim($rawPostalCode) !== ''
            ? strtoupper((string) preg_replace('/\s+/', '', trim($rawPostalCode)))
            : null;

        [$houseNumber, $additionFromHouse] = $this->parseHouseNumberInput($this->input('address_house_number'));
        $addition = strtoupper(trim((string) $this->input('address_house_number_addition')));
        if ($addition === '' && $additionFromHouse !== null) {
            $addition = $additionFromHouse;
        }

        $addressLine = trim((string) $this->input('address_line'));
        $city = trim((string) $this->input('address_city'));
        $email = trim((string) $this->input('customer_email'));

        // Public demo: never show @demo.invalid in the form; mint uniqueness on save.
        if ($email === '' && $this->isPublicDemo()) {
            $email = 'voorbeeld+'.strtolower((string) str()->ulid())
                .(string) config('intake.demo.customer_email_domain', '@demo.invalid');
        }

        $this->merge([
            'customer_email' => $email === '' ? null : $email,
            'address_postal_code' => $postalCode,
            'address_house_number' => $houseNumber,
            'address_house_number_addition' => $addition === '' ? null : $addition,
            'address_line' => $addressLine,
            'address_city' => $city,
            'workflow_mode' => $this->input('workflow_mode', ContributionMode::Customer->value),
        ]);
    }

    private function isPublicDemo(): bool
    {
        return app(PublicDemoSession::class)->isActive($this);
    }

    /**
     * Accept "12", "12A", "12 A", or "12-A" from the huisnummer field.
     *
     * @return array{0: int|string|null, 1: string|null}
     */
    private function parseHouseNumberInput(mixed $raw): array
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit(trim($raw)))) {
            return [(int) $raw, null];
        }

        if (! is_string($raw)) {
            return [$raw, null];
        }

        $trimmed = trim($raw);
        if (preg_match('/^(\d+)\s*[-]?\s*([A-Za-z0-9][A-Za-z0-9\-\s]*)?$/u', $trimmed, $matches) !== 1) {
            return [$raw, null];
        }

        $number = (int) $matches[1];
        $addition = isset($matches[2]) ? strtoupper(trim((string) preg_replace('/\s+/', '-', trim($matches[2])), '-')) : '';

        return [$number, $addition === '' ? null : $addition];
    }
}
