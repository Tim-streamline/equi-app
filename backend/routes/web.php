<?php

use App\Http\Controllers\CommunityController;
use App\Http\Controllers\HorseDashboardController;
use App\Http\Controllers\PowerSyncAuthController;
use App\Http\Controllers\PushTokenController;
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

Route::middleware(AuthenticatePowerSyncJwt::class)->group(function () {
    Route::post('/api/notifications/push-token', [PushTokenController::class, 'store']);
    Route::get('/api/horses/{horse}/dashboard', [HorseDashboardController::class, 'show']);
    Route::match(['get', 'post'], '/api/horses/{horse}/protocol-day', [HorseDashboardController::class, 'day']);
    Route::post('/api/horses/{horse}/weekly-update', [HorseDashboardController::class, 'weeklyUpdate']);
});

Route::middleware(AuthenticatePowerSyncJwt::class)->prefix('api/community')->controller(CommunityController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/posts/{post}', 'show')->whereUuid('post');
    Route::get('/media/{media}', 'media')->whereUuid('media');
    Route::get('/mutes', 'mutes');
    Route::middleware('throttle:community-write')->group(function () {
        Route::post('/posts', 'store');
        Route::patch('/posts/{post}', 'update')->whereUuid('post');
        Route::delete('/posts/{post}', 'destroy')->whereUuid('post');
        Route::post('/posts/{post}/replies', 'replyStore')->whereUuid('post');
        Route::patch('/replies/{reply}', 'replyUpdate')->whereUuid('reply');
        Route::delete('/replies/{reply}', 'replyDestroy')->whereUuid('reply');
        Route::match(['put', 'delete'], '/posts/{post}/bookmark', 'bookmark')->whereUuid('post');
        Route::match(['put', 'delete'], '/{type}/{id}/like', 'like')->whereUuid('id');
        Route::post('/{type}/{id}/report', 'report')->whereUuid('id');
        Route::match(['put', 'delete'], '/mutes/{type}/{id}', 'mute')->whereUuid('id');
    });
});

Route::middleware(AuthenticatePowerSyncJwt::class)->prefix('api/library')->controller(\App\Http\Controllers\LibraryController::class)->group(function () {
    Route::get('/access', 'access');
    Route::get('/bookmarks', 'bookmarks');
    Route::get('/selections/{selection}', 'selection')->where('selection', '[a-z-]+');
    Route::get('/{library}', 'show')->whereUuid('library');
    Route::post('/{library}/unlock', 'unlock')->whereUuid('library')->middleware('throttle:60,1');
    Route::get('/{library}/related', 'related')->whereUuid('library');
    Route::match(['get', 'put', 'delete'], '/{library}/bookmark', 'bookmark')->whereUuid('library');
});

// Browser sessions intentionally live outside api/* so Laravel enforces CSRF.
Route::prefix('web-session')->controller(\App\Http\Controllers\WebSessionController::class)->group(function () {
    Route::get('/csrf', 'csrf');
    Route::post('/login', 'login')->middleware('throttle:10,1');
    Route::post('/token', 'token');
    Route::post('/logout', 'logout');
    Route::get('/media/{media}', [CommunityController::class, 'media'])
        ->whereUuid('media')->middleware(\App\Http\Middleware\AuthenticateWebAppSession::class);
});
