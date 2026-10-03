<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pipeline status for photo assessment (queue ai-photo).
 * Terminal values stop the customer poll; pending is the only non-terminal state.
 */
enum PhotoAssessmentStatus: string
{
    /** Uploaded; waiting for heuristic and/or queued AI. */
    case Pending = 'pending';

    /** AI content check finished (ok / wrong_subject / needs_clearer). */
    case Assessed = 'assessed';

    /** Local usability rejected the photo (too_small / too_dark); no AI wait. */
    case HeuristicRejected = 'heuristic_rejected';

    /** AI failed or was unavailable; soft-fail, customer may continue. */
    case NotAssessed = 'not_assessed';

    /** Same bytes already assessed elsewhere; content copied from source upload. */
    case Reused = 'reused';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isTerminal()),
        ));
    }
}
