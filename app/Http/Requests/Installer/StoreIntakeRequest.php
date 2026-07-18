<?php

declare(strict_types=1);

namespace App\Http\Requests\Installer;

use App\Domains\Intake\Models\Intake;
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
            'address_line' => ['required', 'string', 'max:255'],
            'address_postal_code' => ['nullable', 'string', 'max:20'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'template_key' => ['required', 'string', Rule::exists('intake_templates', 'key')->where('is_active', true)],
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
            'address_line' => 'adres',
            'address_postal_code' => 'postcode',
            'address_city' => 'plaats',
            'internal_note' => 'interne notitie',
            'template_key' => 'type opname',
        ];
    }
}
