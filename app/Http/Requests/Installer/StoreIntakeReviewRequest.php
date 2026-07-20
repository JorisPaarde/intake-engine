<?php

declare(strict_types=1);

namespace App\Http\Requests\Installer;

use App\Domains\Intake\Models\Intake;
use App\Enums\FollowUpItemType;
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
            'follow_up_items' => [
                'exclude_unless:decision,'.ReviewDecision::NeedMoreInfo->value,
                'required_if:decision,'.ReviewDecision::NeedMoreInfo->value,
                'array',
                'min:1',
                'max:'.config('intake.follow_up.max_items_per_round', 5),
            ],
            'follow_up_items.*.type' => ['required', Rule::enum(FollowUpItemType::class)],
            'follow_up_items.*.prompt' => ['required', 'string', 'max:500'],
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
            'follow_up_items' => 'aanvullende vragen',
            'follow_up_items.*.type' => 'antwoordvorm',
            'follow_up_items.*.prompt' => 'vraag, foto- of documentopdracht',
        ];
    }

    protected function prepareForValidation(): void
    {
        $rawItems = $this->input('follow_up_items', []);
        $items = [];

        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                if (! is_array($item) || blank($item['prompt'] ?? null)) {
                    continue;
                }

                $items[] = [
                    'type' => $item['type'] ?? null,
                    'prompt' => trim((string) $item['prompt']),
                ];
            }
        }

        $this->merge([
            'site_visit_needed' => $this->boolean('site_visit_needed'),
            'enough_information' => $this->boolean('enough_information'),
            'follow_up_items' => $items,
        ]);
    }
}
