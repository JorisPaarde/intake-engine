<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Domains\AI\Exceptions\AiClientException;
use Illuminate\Support\Facades\File;

/**
 * Deterministic FakeAiClient scenarios for browser E2E (never calls a real provider).
 * Persisted to a file so artisan helpers and the web/queue process share state.
 */
final class E2eAiScenario
{
    public const GOOD_PHOTO = 'good_photo';

    public const WRONG_SUBJECT = 'wrong_subject';

    public const LOW_RESOLUTION = 'low_resolution';

    public const INVALID_JSON = 'invalid_json';

    public const HTTP_400 = 'http_400';

    /** @var list<string> */
    public const ALL = [
        self::GOOD_PHOTO,
        self::WRONG_SUBJECT,
        self::LOW_RESOLUTION,
        self::INVALID_JSON,
        self::HTTP_400,
    ];

    public static function path(): string
    {
        return storage_path('framework/e2e-ai-scenario.txt');
    }

    public static function get(): string
    {
        $fromEnv = trim((string) env('AI_E2E_SCENARIO', ''));
        if ($fromEnv !== '' && in_array($fromEnv, self::ALL, true)) {
            return $fromEnv;
        }

        $path = self::path();
        if (! is_file($path)) {
            return self::GOOD_PHOTO;
        }

        $value = trim((string) file_get_contents($path));

        return in_array($value, self::ALL, true) ? $value : self::GOOD_PHOTO;
    }

    public static function set(string $scenario): void
    {
        if (! in_array($scenario, self::ALL, true)) {
            throw new \InvalidArgumentException('Unknown E2E AI scenario: '.$scenario);
        }

        File::ensureDirectoryExists(dirname(self::path()));
        File::put(self::path(), $scenario.PHP_EOL);
    }

    public static function clear(): void
    {
        if (is_file(self::path())) {
            @unlink(self::path());
        }
    }

    /**
     * @return array{output: array<string, mixed>, model: string}|null
     *
     * @throws AiClientException
     */
    public static function resolve(string $promptVersion): ?array
    {
        if (! (bool) config('ai.e2e_helpers_enabled', false)
            && (string) config('ai.provider') !== 'fake') {
            return null;
        }

        $scenario = self::get();

        return match ($scenario) {
            self::HTTP_400 => throw new AiClientException('Fake AI HTTP 400 for E2E scenario.'),
            self::INVALID_JSON => throw new AiClientException('Fake AI returned invalid JSON for E2E scenario.'),
            self::WRONG_SUBJECT => self::wrongSubjectOutput($promptVersion),
            // low_resolution is enforced by PhotoUsabilityHeuristic on small fixtures; AI stays default.
            self::LOW_RESOLUTION, self::GOOD_PHOTO => null,
            default => null,
        };
    }

    /**
     * @return array{output: array<string, mixed>, model: string}|null
     */
    private static function wrongSubjectOutput(string $promptVersion): ?array
    {
        if (str_starts_with($promptVersion, 'fusebox-assessment')
            || str_starts_with($promptVersion, 'follow-up-photo-subject')) {
            return [
                'output' => [
                    'empty_module_space' => 'unknown',
                    'phase' => 'unknown',
                    'detected_subject' => 'outdoor_unit',
                    'subject_match' => 'no',
                    'confidence' => 'low',
                    'evidence' => 'E2E: foto toont een buitenunit, geen meterkast.',
                    'retake_instruction' => 'Maak een nieuwe, duidelijke foto van je meterkast.',
                ],
                'model' => 'fake-vision-e2e',
            ];
        }

        if (str_starts_with($promptVersion, 'room-assessment')) {
            return [
                'output' => [
                    'room_type' => 'unknown',
                    'room_size_indication' => 'unknown',
                    'sun_exposure' => 'unknown',
                    'glass_amount' => 'unknown',
                    'glazing_type' => 'unknown',
                    'room_outlet_status' => 'unknown',
                    'extra_overview_needed' => 'unknown',
                    'detected_subject' => 'outdoor_unit',
                    'subject_match' => 'no',
                    'confidence' => 'low',
                    'evidence' => 'E2E: verkeerd onderwerp voor ruimtetfoto.',
                    'retake_instruction' => 'Maak een overzichtsfoto van de kamer.',
                ],
                'model' => 'fake-vision-e2e',
            ];
        }

        return null;
    }
}
