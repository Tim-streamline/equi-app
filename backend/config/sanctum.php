<?php

// Sanctum only stores the app's device tokens (App\Support\DeviceTokens). The
// API keeps authenticating with PowerSync JWTs and the web app with sessions,
// so Sanctum's SPA cookie route is not registered. Other keys use the defaults.
return [
    'routes' => false,
];
