<?php

use App\Http\Controllers\PowerSyncAuthController;
use App\Http\Controllers\SyncController;
use App\Http\Middleware\AuthenticatePowerSyncJwt;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// JWKS endpoint consumed by the PowerSync service (configured as
// POWERSYNC_JWKS_URL). Must be reachable from inside the docker network.
Route::get('/.well-known/jwks.json', [PowerSyncAuthController::class, 'jwks']);

// Token mint endpoint hit by the Expo client from BackendConnector.fetchCredentials().
// CSRF is disabled globally for `api/*` paths in bootstrap/app.php.
Route::post('/api/auth/login', [PowerSyncAuthController::class, 'login']);

// Write-back endpoint hit by BackendConnector.uploadData() — applies a batch
// of CRUD ops against the Postgres source of truth, gated by a valid
// PowerSync JWT signed by us.
Route::middleware(AuthenticatePowerSyncJwt::class)
    ->post('/api/sync/upload', [SyncController::class, 'upload']);
