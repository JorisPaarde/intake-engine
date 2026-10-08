<?php

declare(strict_types=1);

namespace App\Domains\AI\Eval;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Importeert geanonimiseerde AI-trace JSONL naar eval-fixtures.
 * Draait nooit tegen staging/prod — alleen lokaal op een exportbestand.
 */
final class TraceFixtureImporter
{
    /**
     * @return array{imported: int, skipped: int, written: list<string>, privacy_hits: list<string>}
     */
    public function import(string $jsonlPath, bool $dryRun = false): array
    {
        if (! File::isFile($jsonlPath)) {
            throw new RuntimeException("JSONL niet gevonden: {$jsonlPath}");
        }

        $lines = file($jsonlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException("Kon JSONL niet lezen: {$jsonlPath}");
        }

        $imported = 0;
        $skipped = 0;
        $written = [];
        $privacyHits = [];

        foreach ($lines as $index => $line) {
            $row = json_decode($line, true);
            if (! is_array($row)) {
                $skipped++;

                continue;
            }

            $text = $this->extractRequestReason($row);
            if ($text === null || mb_strlen($text) < 10) {
                $skipped++;

                continue;
            }

            $hits = $this->privacyScan($text);
            if ($hits !== []) {
                $privacyHits[] = 'line '.($index + 1).': '.implode(', ', $hits);
                $text = $this->anonymize($text);
            }

            $intakeRef = $row['intake_ref_id'] ?? $row['intake_id'] ?? ($index + 1);
            $id = 'import-'.preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $intakeRef);
            $fixture = [
                'id' => $id,
                'kind' => 'request_text',
                'origin' => 'imported from ai:traces:export',
                'source_kind' => 'verbatim_trace',
                'text' => $text,
                'expected' => [], // invullen door reviewer
                'components' => ['C1', 'C2', 'C3', 'C4', 'C8'],
                'note' => 'Auto-import: expected nog handmatig te zetten. Privacy-scan uitgevoerd.',
                'disputed_facts' => [],
            ];

            $path = base_path('tests/Eval/fixtures/request_texts/'.$id.'.json');
            if (! $dryRun) {
                File::ensureDirectoryExists(dirname($path));
                File::put(
                    $path,
                    json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
                );
            }
            $written[] = $path;
            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'written' => $written,
            'privacy_hits' => $privacyHits,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractRequestReason(array $row): ?string
    {
        $candidates = [
            $row['request_reason'] ?? null,
            $row['input']['known_context']['request_reason'] ?? null,
            $row['request']['known_context']['request_reason'] ?? null,
            $row['parsed_response']['request_reason'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        // Sommige exports stoppen context in steps
        if (isset($row['steps']) && is_array($row['steps'])) {
            foreach ($row['steps'] as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $payload = $step['payload'] ?? $step['data'] ?? null;
                if (is_array($payload) && is_string($payload['request_reason'] ?? null)) {
                    return trim($payload['request_reason']);
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function privacyScan(string $text): array
    {
        $hits = [];
        if (preg_match('/\b\d{4}\s?[A-Z]{2}\b/u', $text) === 1) {
            $hits[] = 'postcode';
        }
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text) === 1) {
            $hits[] = 'email';
        }
        if (preg_match('/(\+?\d{1,3}[\s-]?)?\(?\d{2,4}\)?[\s-]?\d{3,4}[\s-]?\d{3,4}/', $text) === 1
            && preg_match('/\d{6,}/', $text) === 1) {
            $hits[] = 'telefoonpatroon';
        }
        // Veelvoorkomende NL straat-suffixen met huisnummer
        if (preg_match('/\b([A-Z][a-z]+(?:straat|laan|weg|pad|singel|gracht|dijk))\s+\d{1,5}\b/u', $text) === 1) {
            $hits[] = 'straat+huisnummer';
        }

        return $hits;
    }

    private function anonymize(string $text): string
    {
        $text = preg_replace('/\b\d{4}\s?[A-Z]{2}\b/u', '[POSTCODE]', $text) ?? $text;
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[EMAIL]', $text) ?? $text;
        $text = preg_replace(
            '/\b([A-Z][a-z]+(?:straat|laan|weg|pad|singel|gracht|dijk))\s+\d{1,5}\b/u',
            '[ADRES]',
            $text,
        ) ?? $text;

        return $text;
    }
}
