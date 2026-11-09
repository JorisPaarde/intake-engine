<?php

declare(strict_types=1);

namespace App\Domains\Intake\Support;

/**
 * Vaste sleutel/e-mail voor de staging-smoke van het embed-formulier (BL-153).
 * Geen echte klantdata; alleen local/staging via artisan + /embed-test.
 */
final class EmbedStagingTest
{
    public const PUBLIC_KEY = 'ie_embed_staging_smoke_v1';

    public const COMPANY_NAME = 'Embed Staging Testbedrijf';

    public const USER_EMAIL = 'embed-staging-test@example.com';

    public const USER_PASSWORD = 'password';

    public const USER_NAME = 'Embed Staging Tester';
}
