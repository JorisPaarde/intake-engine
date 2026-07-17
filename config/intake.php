<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Customer access token lifetime
    |--------------------------------------------------------------------------
    |
    | Number of days a customer intake link remains valid after creation or
    | regeneration, unless revoked earlier.
    |
    */

    'token_ttl_days' => (int) env('INTAKE_TOKEN_TTL_DAYS', 60),

];
