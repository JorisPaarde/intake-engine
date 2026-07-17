<?php

declare(strict_types=1);

namespace App\Http\Requests\Installer;

use App\Domains\Intake\Models\Intake;
use App\Enums\ReviewDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntakeReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Intake $intake */
        $intake = $this->route('intake');

        return $this->user()?->can('review', $intake) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                Rule::enum(ReviewDecision::class)->except([ReviewDecision::Pending]),
            ],
            'site_visit_needed' => ['sometimes', 'boolean'],
            'enough_information' => ['sometimes', 'boolean'],
            'summary' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'decision' => 'beoordeling',
            'site_visit_needed' => 'locatiebezoek nodig',
            'enough_information' => 'voldoende informatie',
            'summary' => 'samenvatting',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'site_visit_needed' => $this->boolean('site_visit_needed'),
            'enough_information' => $this->boolean('enough_information'),
        ]);
    }
}
