<?php

declare(strict_types=1);

namespace App\Domains\AI\Actions;

use App\Domains\AI\Services\AiGateway;
use App\Domains\AI\Services\PromptVersionRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Structured height hints from a follow-up text answer (ADR-0016).
 *
 * Code does not parse meaning of "nok" / "knieschot"; the model returns the
 * numbers. A number is kept only when it appears literally in the source text.
 */
final class InterpretFollowUpText
{
    /**
     * @return array{
     *     peak_height_m: float|null,
     *     knee_wall_height_m: float|null,
     *     mentions_sloped_roof: bool
     * }
     */
    public const EMPTY_HEIGHT_HINTS = [
        'peak_height_m' => null,
        'knee_wall_height_m' => null,
        'mentions_sloped_roof' => false,
    ];

    public function __construct(
        private readonly AiGateway $aiGateway,
        private readonly PromptVersionRepository $promptVersions,
    ) {}

    /**
     * @return array{
     *     peak_height_m: float|null,
     *     knee_wall_height_m: float|null,
     *     mentions_sloped_roof: bool
     * }
     */
    public function extractHeightHints(string $customerText): array
    {
        $empty = self::EMPTY_HEIGHT_HINTS;
        $text = trim($customerText);

        if ($text === '') {
            return $empty;
        }

        if (! (bool) config('ai.text_inference.enabled', false)) {
            return $empty;
        }

        $provider = (string) config('ai.provider', 'null');
        if (in_array($provider, ['null', ''], true)) {
            return $empty;
        }

        $promptName = (string) config('ai.follow_up_text_prompt', 'follow_up_text');

        try {
            $result = $this->aiGateway->complete(
                prompt: $this->promptVersions->body($promptName),
                input: [
                    'task' => 'follow_up_height_hints',
                    'customer_text' => $text,
                ],
                promptVersion: $this->promptVersions->version($promptName),
                temperature: (float) config('ai.classification_temperature', 0),
            );
        } catch (Throwable $exception) {
            Log::info('follow_up_text.extract_failed', [
                'message' => $exception->getMessage(),
            ]);

            return $empty;
        }

        $output = $result->output;

        return [
            'peak_height_m' => $this->acceptedNumber($output['peak_height_m'] ?? null, $text),
            'knee_wall_height_m' => $this->acceptedNumber($output['knee_wall_height_m'] ?? null, $text),
            'mentions_sloped_roof' => ($output['mentions_sloped_roof'] ?? false) === true,
        ];
    }

    private function acceptedNumber(mixed $raw, string $source): ?float
    {
        if (! is_numeric($raw)) {
            return null;
        }

        $number = round((float) $raw, 2);
        if ($number <= 0 || $number > 20) {
            return null;
        }

        return $this->numberAppearsInSource($number, $source) ? $number : null;
    }

    private function numberAppearsInSource(float $number, string $source): bool
    {
        $normalizedSource = str_replace(',', '.', $source);
        $variants = array_unique([
            (string) $number,
            number_format($number, 1, '.', ''),
            number_format($number, 1, ',', ''),
            number_format($number, 2, '.', ''),
            number_format($number, 2, ',', ''),
        ]);

        foreach ($variants as $variant) {
            if ($variant !== '' && (str_contains($source, $variant) || str_contains($normalizedSource, str_replace(',', '.', $variant)))) {
                return true;
            }
        }

        return false;
    }
}
