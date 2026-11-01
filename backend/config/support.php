<?php

return [
    // Only a digest of a randomly generated key belongs in server configuration.
    'master_key_hash' => env('SUPPORT_MASTER_KEY_HASH', ''),
    'master_key_enabled' => (bool) env('SUPPORT_MASTER_KEY_ENABLED', false),
];
