<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Deterministic PDOK suggest stub for Playwright embed E2E (never calls real PDOK).
 * File-backed so the web process can enable it via /__e2e__/embed-address-stub.
 */
final class E2eEmbedAddressStub
{
    public static function path(): string
    {
        return storage_path('framework/e2e-embed-address-stub.json');
    }

    public static function enabled(): bool
    {
        return (bool) config('ai.e2e_helpers_enabled', false) && is_file(self::path());
    }

    public static function enableDefault(): void
    {
        $payload = [
            'enabled' => true,
            'suggestions' => [[
                'id' => 'adr-e2e-bernadotte-273',
                'label' => 'Bernadottelaan 273, 2037GR Haarlem',
                'address_line' => 'Bernadottelaan 273',
                'postal_code' => '2037GR',
                'house_number' => 273,
                'house_number_addition' => null,
                'city' => 'Haarlem',
            ]],
        ];

        $dir = dirname(self::path());
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(self::path(), json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public static function clear(): void
    {
        if (is_file(self::path())) {
            unlink(self::path());
        }
    }

    /**
     * @return list<array{id: string, label: string, address_line: string, postal_code: string, house_number: int, house_number_addition: string|null, city: string}>
     */
    public static function suggestionsFor(string $postalCode, int $houseNumber, ?string $addition): array
    {
        if (! self::enabled()) {
            return [];
        }

        $raw = file_get_contents(self::path());
        if ($raw === false || $raw === '') {
            return [];
        }

        /** @var array{suggestions?: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $suggestions = $decoded['suggestions'] ?? [];

        $normalizedPostal = strtoupper((string) preg_replace('/\s+/', '', $postalCode));
        $normalizedAddition = strtoupper(trim((string) $addition));
        $normalizedAddition = $normalizedAddition === '' ? null : $normalizedAddition;

        $matches = [];
        foreach ($suggestions as $suggestion) {
            $suggestionPostal = strtoupper((string) preg_replace('/\s+/', '', (string) ($suggestion['postal_code'] ?? '')));
            $suggestionNumber = (int) ($suggestion['house_number'] ?? 0);
            $suggestionAddition = isset($suggestion['house_number_addition']) && is_string($suggestion['house_number_addition'])
                ? strtoupper(trim($suggestion['house_number_addition']))
                : null;
            $suggestionAddition = $suggestionAddition === '' ? null : $suggestionAddition;

            if ($suggestionPostal !== $normalizedPostal || $suggestionNumber !== $houseNumber) {
                continue;
            }

            if ($normalizedAddition !== null && $suggestionAddition !== $normalizedAddition) {
                continue;
            }

            $matches[] = [
                'id' => (string) ($suggestion['id'] ?? ''),
                'label' => (string) ($suggestion['label'] ?? ''),
                'address_line' => (string) ($suggestion['address_line'] ?? ''),
                'postal_code' => (string) ($suggestion['postal_code'] ?? ''),
                'house_number' => $suggestionNumber,
                'house_number_addition' => $suggestionAddition,
                'city' => (string) ($suggestion['city'] ?? ''),
            ];
        }

        return array_values(array_filter(
            $matches,
            static fn (array $row): bool => $row['id'] !== '' && str_starts_with($row['id'], 'adr-'),
        ));
    }
}
