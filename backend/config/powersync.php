<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PowerSync JWT signing
    |--------------------------------------------------------------------------
    |
    | The mobile client gets a short-lived RS256 JWT from the API; PowerSync
    | verifies the signature against the JWKS we serve from
    | /.well-known/jwks.json. The keypair lives in storage/app/private/ by
    | default and can be generated with `php artisan powersync:generate-keys`.
    |
    */

    'private_key_path' => env('POWERSYNC_PRIVATE_KEY_PATH', storage_path('app/private/powersync_private.pem')),
    'public_key_path' => env('POWERSYNC_PUBLIC_KEY_PATH', storage_path('app/private/powersync_public.pem')),

    // Used as the `kid` claim in the JWT header and the matching JWKS entry.
    'key_id' => env('POWERSYNC_KEY_ID', 'powersync-dev-1'),

    // Issuer + audience embedded in minted JWTs. Audience must be one of the
    // values listed under `client_auth.audience` in powersync/service.yaml.
    'issuer' => env('POWERSYNC_ISSUER', 'equinova-laravel'),
    'audience' => env('POWERSYNC_AUDIENCE', 'equinova'),

    // Token lifetime in seconds. PowerSync expects short-lived tokens that
    // the client refreshes periodically via fetchCredentials().
    'token_ttl' => (int) env('POWERSYNC_TOKEN_TTL', 60 * 60),

    // The PowerSync service URL the client should connect to. The Expo app
    // returns this from fetchCredentials() alongside the token.
    'service_url' => env('POWERSYNC_SERVICE_URL', 'http://localhost:8080'),
];
