<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use App\Domains\AI\Models\AiRun;
use Illuminate\Support\Facades\Log;

/**
 * Turns a partial (or failed) dossier-synthesis AiRun into a short installer flash
 * message. Technical section keys (placement_proposals.0, …) stay in logs / optional
 * admin detail — never in the primary status line.
 */
final class DossierSynthesisRefreshPresenter
{
    /**
     * @return array{message: string, technical_detail: string|null}
     */
    public function partialFlash(AiRun $run): array
    {
        $output = is_array($run->output) ? $run->output : [];
        $technical = $this->technicalDetail($run);

        if ($technical !== null) {
            Log::info('AI dossier synthesis partial refresh (technical)', [
                'ai_run_id' => $run->id,
                'intake_id' => $run->intake_id,
                'detail' => $technical,
            ]);
        }

        return [
            'message' => $this->buildPartialMessage($output, $technical),
            'technical_detail' => $technical,
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function buildPartialMessage(array $output, ?string $technical): string
    {
        $accepted = $this->acceptedParts($output);
        $rejected = $this->rejectedParts($technical, $output);

        $message = 'AI-voorstel deels vernieuwd';

        if ($accepted !== []) {
            $message .= ': '.$this->joinDutch($accepted).' '.$this->zijnOfIs($accepted).' bijgewerkt';
        }

        if ($rejected !== []) {
            $message .= ($accepted !== [] ? '; ' : ': ').implode('; ', $rejected);
        }

        if ($accepted === [] && $rejected === []) {
            $message .= ': een deel van de AI-voorstellen is overgenomen; controleer wat ontbreekt';
        }

        return rtrim($message, '.').'.';
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    private function acceptedParts(array $output): array
    {
        $parts = [];

        if (trim((string) ($output['summary'] ?? '')) !== '') {
            $parts[] = 'de samenvatting';
        }

        $placementCount = count($output['placement_proposals'] ?? []);
        if ($placementCount === 1) {
            $parts[] = '1 positie';
        } elseif ($placementCount > 1) {
            $parts[] = $placementCount.' posities';
        }

        $optionCount = count($output['option_proposals'] ?? []);
        if ($optionCount === 1) {
            $parts[] = '1 systeemvoorstel';
        } elseif ($optionCount > 1) {
            $parts[] = $optionCount.' systeemvoorstellen';
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    private function rejectedParts(?string $technical, array $output): array
    {
        if ($technical === null || $technical === '') {
            return [];
        }

        $keys = $this->sectionKeysFromTechnical($technical);
        $parts = [];

        $optionRejected = $this->sectionRejected($keys, 'option_proposals');
        $placementRejected = $this->sectionRejected($keys, 'placement_proposals');
        $summaryRejected = in_array('summary', $keys, true);

        $acceptedOptions = count($output['option_proposals'] ?? []);
        $acceptedPlacements = count($output['placement_proposals'] ?? []);

        if ($optionRejected && $acceptedOptions === 0) {
            $parts[] = 'het systeemvoorstel kon niet worden onderbouwd en is ongewijzigd gebleven';
        } elseif ($optionRejected) {
            $parts[] = 'niet elk systeemvoorstel kon worden onderbouwd';
        }

        if ($placementRejected && $acceptedPlacements === 0) {
            $parts[] = 'posities konden niet betrouwbaar worden vastgelegd';
        }

        // Summary rejection with a sanitized replacement still counts as “bijgewerkt”;
        // only mention summary failure when nothing usable landed.
        if ($summaryRejected && trim((string) ($output['summary'] ?? '')) === '') {
            $parts[] = 'de samenvatting kon niet worden overgenomen';
        }

        return $parts;
    }

    /**
     * @return list<string>
     */
    private function sectionKeysFromTechnical(string $technical): array
    {
        $keys = [];
        foreach (preg_split('/\s*\|\s*/', $technical) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^([a-z0-9_.]+)\s*:/i', $part, $matches) === 1) {
                $keys[] = strtolower($matches[1]);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<string>  $keys
     */
    private function sectionRejected(array $keys, string $section): bool
    {
        foreach ($keys as $key) {
            if ($key === $section || str_starts_with($key, $section.'.')) {
                return true;
            }
        }

        return false;
    }

    private function technicalDetail(AiRun $run): ?string
    {
        $detail = is_string($run->error_message) ? trim($run->error_message) : '';

        return $detail !== '' ? $detail : null;
    }

    /**
     * @param  list<string>  $parts
     */
    private function joinDutch(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' en '.$last;
    }

    /**
     * @param  list<string>  $parts
     */
    private function zijnOfIs(array $parts): string
    {
        if (count($parts) === 1 && str_starts_with($parts[0], 'de ')) {
            return 'is';
        }

        return 'zijn';
    }
}
