<?php

use App\Http\Controllers\CommunityController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\HorseDashboardController;
use App\Http\Controllers\IntakeAttachmentController;
use App\Http\Controllers\LibraryAttachmentController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PlusPageController;
use App\Http\Controllers\PowerSyncAuthController;
use App\Http\Controllers\PushTokenController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\WebSessionController;
use App\Http\Middleware\AuthenticatePowerSyncJwt;
use App\Http\Middleware\AuthenticateWebAppSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// JWKS endpoint consumed by the PowerSync service (configured as
// POWERSYNC_JWKS_URL). Must be reachable from inside the docker network.
Route::get('/.well-known/jwks.json', [PowerSyncAuthController::class, 'jwks']);

// Token mint endpoint hit by the Expo client from BackendConnector.fetchCredentials().
// CSRF is disabled globally for `api/*` paths in bootstrap/app.php.
Route::post('/api/auth/register', [RegistrationController::class, 'store'])->middleware('throttle:5,1,registration-start:');
Route::get('/registration/confirm/{uid}', [RegistrationController::class, 'confirm'])->whereUuid('uid')->middleware('throttle:60,1,registration-link:');
Route::post('/api/auth/register/status', [RegistrationController::class, 'status'])->middleware('throttle:60,1,registration-status:');
Route::post('/api/auth/register/complete', [RegistrationController::class, 'complete'])->middleware('throttle:10,1,registration-complete:');
Route::post('/api/auth/forgot-password', [PasswordResetController::class, 'requestLink'])->middleware('throttle:5,1,password-reset:');
Route::get('/web-session/password/reset/{token}', [PasswordResetController::class, 'show'])->name('password.reset');
Route::post('/web-session/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1,password-reset-submit:')->name('password.update');

Route::post('/api/auth/login', [PowerSyncAuthController::class, 'login']);

// Write-back endpoint hit by BackendConnector.uploadData() — applies a batch
// of CRUD ops against the Postgres source of truth, gated by a valid
// PowerSync JWT signed by us.
Route::middleware(AuthenticatePowerSyncJwt::class)
    ->post('/api/sync/upload', [SyncController::class, 'upload']);

Route::middleware(AuthenticatePowerSyncJwt::class)->group(function () {
    Route::post('/api/intakes/{id}/attachments', [IntakeAttachmentController::class, 'store'])->whereUuid('id')->middleware('throttle:60,1');
    Route::get('/api/intake-media/{attachment}', [IntakeAttachmentController::class, 'show'])->whereUuid('attachment');
    Route::post('/api/notifications/push-token', [PushTokenController::class, 'store']);
    Route::get('/api/horses/{horse}/dashboard', [HorseDashboardController::class, 'show']);
    Route::get('/api/home', [HorseDashboardController::class, 'home']);
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

Route::middleware(AuthenticatePowerSyncJwt::class)->prefix('api/library')->controller(LibraryController::class)->group(function () {
    Route::get('/access', 'access');
    Route::get('/bookmarks', 'bookmarks');
    Route::get('/selections/{selection}', 'selection')->where('selection', '[a-z-]+');
    Route::get('/{library}', 'show')->whereUuid('library');
    Route::post('/{library}/unlock', 'unlock')->whereUuid('library')->middleware('throttle:60,1');
    Route::get('/{library}/related', 'related')->whereUuid('library');
    Route::post('/{library}/attachments/{attachment}/open', [LibraryAttachmentController::class, 'open'])->whereUuid(['library', 'attachment']);
    Route::match(['get', 'put', 'delete'], '/{library}/bookmark', 'bookmark')->whereUuid('library');
});

// Browser sessions intentionally live outside api/* so Laravel enforces CSRF.
Route::prefix('web-session')->controller(WebSessionController::class)->group(function () {
    Route::post('/register', [RegistrationController::class, 'store'])->name('web-session.register')->middleware('throttle:5,1,registration-start:');
    Route::post('/register/status', [RegistrationController::class, 'status'])->middleware('throttle:60,1,registration-status:');
    Route::post('/register/complete', [RegistrationController::class, 'complete'])->name('web-session.register.complete')->middleware('throttle:10,1,registration-complete:');
    Route::post('/forgot-password', [PasswordResetController::class, 'requestLink'])->middleware('throttle:5,1,password-reset:');
    Route::get('/csrf', 'csrf');
    Route::post('/login', 'login')->middleware('throttle:10,1');
    Route::post('/token', 'token');
    Route::post('/logout', 'logout');
    Route::get('/media/{media}', [CommunityController::class, 'media'])
        ->whereUuid('media')->middleware(AuthenticateWebAppSession::class);
});

Route::middleware(AuthenticatePowerSyncJwt::class)->prefix('api/library/credits')->controller(CreditController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/purchase', 'purchase')->middleware('throttle:15,1');
    Route::post('/temporary-top-up', 'temporaryTopUp')->middleware('throttle:15,1');
    Route::get('/orders/{order}', 'order')->whereUuid('order');
    Route::post('/cancel-basic', 'cancel');
});

Route::get('/api/library-attachments/{attachment}', [LibraryAttachmentController::class, 'show'])
    ->whereUuid('attachment')->middleware('signed')->name('library.attachment');

Route::get('/api/plus-page', [PlusPageController::class, 'show']);
Route::get('/api/plus-page/images/{kind}', [PlusPageController::class, 'image'])->where('kind', 'hero|portrait');
