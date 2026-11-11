<?php

declare(strict_types=1);

use App\Domains\AI\Support\PhotoSubject;
use App\Domains\Intake\Support\CustomerFacingTaskText;

test('installer mismatch diagnosis is never customer-facing', function () {
    $installer = 'Ontvangen foto lijkt een buitenunit, geen meterkast — handmatig controleren';

    expect(CustomerFacingTaskText::isInstallerInternal($installer))->toBeTrue()
        ->and(CustomerFacingTaskText::ensureCustomerFacing($installer))
        ->toBe('Maak een nieuwe, duidelijke foto van je meterkast')
        ->and(CustomerFacingTaskText::ensureCustomerFacing($installer))
        ->not->toContain('handmatig controleren');
});

test('meterkast phase language is rewritten to neutral customer text', function () {
    $technical = 'Maak een duidelijke foto van de meterkast. Daaruit volgt 1- of 3-fase.';

    expect(CustomerFacingTaskText::ensureCustomerFacing($technical))
        ->toBe(CustomerFacingTaskText::fuseboxPhotoPrompt())
        ->and(CustomerFacingTaskText::fuseboxPhotoPrompt())
        ->toContain('groepenkast volledig leesbaar')
        ->and(CustomerFacingTaskText::fuseboxPhotoPrompt())
        ->toContain('installateur beoordeelt de aansluiting')
        ->and(CustomerFacingTaskText::fuseboxPhotoPrompt())
        ->not->toContain('1- of 3-fase');
});

test('ordinary customer prompts pass through unchanged', function () {
    $prompt = 'Meet of noteer de hoogte van Zolder 1.';

    expect(CustomerFacingTaskText::ensureCustomerFacing($prompt))->toBe($prompt);
});

test('DIN-rail jargon in meterkast-retake wordt klanttaal', function () {
    $raw = 'Fotografeer dichterbij zodat lege DIN-railposities zichtbaar zijn.';

    expect(CustomerFacingTaskText::containsHardwareJargon($raw))->toBeTrue()
        ->and(CustomerFacingTaskText::sanitizePhotoRetake(PhotoSubject::Fusebox, $raw))
        ->toBe(CustomerFacingTaskText::fuseboxRetakePrompt())
        ->and(CustomerFacingTaskText::sanitizePhotoRetake(PhotoSubject::Fusebox, $raw))
        ->not->toContain('DIN')
        ->and(CustomerFacingTaskText::sanitizePhotoRetake(PhotoSubject::Fusebox, $raw))
        ->toContain('vrije plek voor een extra groep');
});

test('tegenstrijdige buitenunit-retake wordt één vaste tip', function () {
    $mobile = 'Maak een foto van de hele gevel.';
    $desktop = 'Fotografeer één specifieke muur dichterbij.';

    expect(CustomerFacingTaskText::sanitizePhotoRetake(PhotoSubject::OutdoorLocation, $mobile))
        ->toBe(CustomerFacingTaskText::OUTDOOR_PHOTO_TIP)
        ->and(CustomerFacingTaskText::sanitizePhotoRetake(PhotoSubject::OutdoorLocation, $desktop))
        ->toBe(CustomerFacingTaskText::OUTDOOR_PHOTO_TIP)
        ->and(CustomerFacingTaskText::outdoorPhotoTip())
        ->toContain('gevel of buitenmuur');
});
