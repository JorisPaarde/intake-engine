<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Enums\AttentionPointSource;
use App\Enums\AttentionPointStatus;
use Illuminate\Support\Collection;

/**
 * Toont een AI-voorstel niet als het alleen een Actueel-systeempunt herhaalt.
 * Ontdubbelt uitsluitend op code en evidence-referenties (geen tekstvergelijking).
 */
final class AttentionProposalVisibility
{
    /**
     * @param  Collection<int, IntakeAttentionPoint>  $attentionPoints
     * @return Collection<int, IntakeAttentionPoint>
     */
    public static function visibleProposed(Collection $attentionPoints): Collection
    {
        $authoritativeCodes = $attentionPoints
            ->filter(static function (IntakeAttentionPoint $point): bool {
                $statusOk = $point->status === null
                    || $point->status === AttentionPointStatus::Accepted;

                return $statusOk && ! $point->is_resolved;
            })
            ->pluck('code')
            ->filter(static fn (mixed $code): bool => is_string($code) && $code !== '')
            ->values()
            ->all();

        $authoritativeCodeSet = array_fill_keys($authoritativeCodes, true);

        return $attentionPoints
            ->filter(static function (IntakeAttentionPoint $point) use ($authoritativeCodeSet): bool {
                if ($point->source !== AttentionPointSource::Ai
                    || $point->status !== AttentionPointStatus::Proposed) {
                    return false;
                }

                return ! self::isRedundantProposal($point, $authoritativeCodeSet);
            })
            ->values();
    }

    /**
     * @param  array<string, true>  $authoritativeCodeSet
     */
    public static function isRedundantProposal(IntakeAttentionPoint $point, array $authoritativeCodeSet): bool
    {
        $code = $point->code;
        if (is_string($code) && $code !== '' && isset($authoritativeCodeSet[$code])) {
            return true;
        }

        $evidence = $point->evidence;
        if ($evidence === null || $evidence === []) {
            return false;
        }

        $systemRefs = [];
        foreach ($evidence as $item) {
            if ($item['source_type'] !== 'system_attention_point' || $item['reference'] === '') {
                // Extra bewijs naast een systeempunt → voorstel mag blijven.
                return false;
            }

            $systemRefs[] = $item['reference'];
        }

        foreach ($systemRefs as $reference) {
            if (! isset($authoritativeCodeSet[$reference])) {
                return false;
            }
        }

        return true;
    }
}
