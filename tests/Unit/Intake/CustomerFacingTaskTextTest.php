<?php

declare(strict_types=1);

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
