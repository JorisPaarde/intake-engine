<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use RuntimeException;

/**
 * Optimistic lock on dossier synthesis: context changed between provider call and apply.
 *
 * Re-thrown after the AiRun is marked failed so SynthesizeSurveyDossierJob can retry once.
 */
final class DossierContextChangedException extends RuntimeException {}
