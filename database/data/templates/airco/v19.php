<?php

declare(strict_types=1);

/**
 * Airco template v19 — klanttest P1 foto-stelligheid / categoriefeedback (BL-119 / PR #119).
 *
 * Geen nieuwe vragen of herordening t.o.v. v18 (foto-first van BL-118/#116). Runtime dekt
 * content_assessment, InternalCustomerQuestions en TechnicalDecisionKeys.
 *
 * Gepubliceerde v1–v18 blijven ongewijzigd (ADR-0001).
 *
 * @return array<string, mixed>
 */

/** @var array<string, mixed> $config */
$config = require __DIR__.'/v18.php';

$config['version'] = 19;
$config['change_notes'] = 'Klanttest P1/BL-119: foto-categoriecheck + soft-continue; interne AI-velden uit klantflow; routeconclusies via TechnicalDecisionKeys (geen templatewijziging t.o.v. v18).';

return $config;
