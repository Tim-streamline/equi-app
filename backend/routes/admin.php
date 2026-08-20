<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ChunkedMediaUploadController;
use App\Http\Controllers\Admin\CommunityController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DataExportController;
use App\Http\Controllers\Admin\FocusTopicController;
use App\Http\Controllers\Admin\HorseController;
use App\Http\Controllers\Admin\IngredientController;
use App\Http\Controllers\Admin\IntakeBookingController;
use App\Http\Controllers\Admin\LibraryCategoryController;
use App\Http\Controllers\Admin\LibraryItemController;
use App\Http\Controllers\Admin\MediaAssetController;
use App\Http\Controllers\Admin\ModerationReportController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\NovaController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProtocolController;
use App\Http\Controllers\Admin\ProtocolSettingsController;
use App\Http\Controllers\Admin\ScanResultController;
use App\Http\Controllers\Admin\SeasonalTipController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SyncHealthController;
use App\Http\Controllers\Admin\TherapistController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Admin console. Mounted at /admin with the `admin.` name prefix and the
 * `web` middleware group (see bootstrap/app.php). Everything except the login
 * screen sits behind the `admin` auth guard.
 */

// Guest auth.
Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login'])->name('login.attempt');

Route::middleware('auth:admin')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // ---- Support console: users, horses ---------------------------------
    Route::controller(UserController::class)->prefix('users')->as('users.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{user}', 'show')->name('show');
        Route::get('{user}/edit', 'edit')->name('edit');
        Route::put('{user}', 'update')->name('update');
        Route::post('{user}/disable', 'disable')->name('disable');
        Route::post('{user}/restore', 'restore')->name('restore');
        Route::post('{user}/reset-password', 'resetPassword')->name('reset-password');
        Route::post('{user}/restrict', 'restrict')->name('restrict');
        Route::post('{user}/data-export', 'requestExport')->name('data-export');
    });

    Route::controller(HorseController::class)->prefix('horses')->as('horses.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{horse}', 'show')->name('show');
        Route::put('{horse}', 'update')->name('update');
        Route::post('{horse}/archive', 'archive')->name('archive');
        Route::post('{horse}/restore', 'restore')->name('restore');
        Route::post('{horse}/transfer', 'transfer')->name('transfer');
    });

    // ---- Therapists ------------------------------------------------------
    Route::resource('therapists', TherapistController::class)
        ->except(['create', 'show'])
        ->middleware('admin.role:admin,therapist_admin');

    // ---- Content / CMS ---------------------------------------------------
    Route::middleware('admin.role:content_editor')->group(function () {
        // Media upload/delete must be declared before the library resource so
        // `library/media` isn't shadowed by the `library/{library}` binding.
        Route::post('library/media/chunks', [ChunkedMediaUploadController::class, 'store'])->name('library.media.chunks.store');
        Route::patch('library/media/chunks/{upload}', [ChunkedMediaUploadController::class, 'update'])->name('library.media.chunks.update');
        Route::match(['HEAD'], 'library/media/chunks/{upload}', [ChunkedMediaUploadController::class, 'offset'])->name('library.media.chunks.offset');
        Route::get('library/media/chunks/{upload}/asset', [ChunkedMediaUploadController::class, 'asset'])->name('library.media.chunks.asset');
        Route::delete('library/media/chunks', [ChunkedMediaUploadController::class, 'destroy'])->name('library.media.chunks.destroy');
        Route::post('library/media', [MediaAssetController::class, 'store'])->name('library.media.store');
        Route::delete('library/media/{medium}', [MediaAssetController::class, 'destroy'])->name('library.media.destroy');
        Route::resource('library', LibraryItemController::class)->except('show');
        Route::resource('library-categories', LibraryCategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('focus-topics', FocusTopicController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('seasonal-tips', SeasonalTipController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('nova', NovaController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    // ---- Scanner: products, ingredients, results ------------------------
    Route::resource('products', ProductController::class)->except(['create', 'show']);
    Route::resource('ingredients', IngredientController::class)->except(['create', 'show']);
    Route::controller(ScanResultController::class)->prefix('scans')->as('scans.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{scan}', 'show')->name('show');
        Route::post('{scan}/flag', 'flag')->name('flag');
        Route::delete('{scan}', 'destroy')->name('destroy');
    });

    // ---- Community moderation -------------------------------------------
    Route::middleware('admin.role:moderator,therapist_admin')->group(function () {
        Route::controller(CommunityController::class)->prefix('community')->as('community.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('post/{post}', 'show')->name('show');
            Route::post('post/{post}/moderate', 'moderatePost')->name('post.moderate');
            Route::delete('post/{post}', 'destroyPost')->name('post.destroy');
            Route::post('reply/{reply}/moderate', 'moderateReply')->name('reply.moderate');
            Route::delete('reply/{reply}', 'destroyReply')->name('reply.destroy');
            Route::post('post/{post}/expert-reply', 'expertReply')->name('post.expert-reply');
            Route::post('post/{post}/recount', 'recount')->name('post.recount');
        });
        Route::controller(ModerationReportController::class)->prefix('reports')->as('reports.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('{report}/resolve', 'resolve')->name('resolve');
        });
    });

    // ---- Intake bookings -------------------------------------------------
    Route::controller(IntakeBookingController::class)->prefix('bookings')->as('bookings.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{booking}', 'show')->name('show');
        Route::post('{booking}/status', 'updateStatus')->name('status');
        Route::put('{booking}', 'update')->name('update');
    });

    // ---- Billing ---------------------------------------------------------
    Route::middleware('admin.role:billing')->group(function () {
        Route::resource('plans', PlanController::class)->except(['create', 'show']);
        Route::controller(SubscriptionController::class)->prefix('subscriptions')->as('subscriptions.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{subscription}', 'show')->name('show');
            Route::put('{subscription}', 'update')->name('update');
        });
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    });

    // ---- Protocols -------------------------------------------------------
    Route::controller(ProtocolController::class)->prefix('protocols')->as('protocols.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('{protocol}/edit', 'edit')->name('edit');
        Route::put('{protocol}', 'update')->name('update');
        Route::get('{protocol}', 'show')->name('show');
        Route::post('{protocol}/status', 'updateStatus')->name('status');
    });

    // ---- Operations ------------------------------------------------------
    Route::get('exports', [DataExportController::class, 'index'])->name('exports.index');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('sync-health', [SyncHealthController::class, 'index'])->name('sync-health.index');
    Route::get('audit-log', [AuditLogController::class, 'index'])
        ->middleware('admin.role:admin')->name('audit.index');

    // ---- Settings: admin users ------------------------------------------
    Route::middleware('admin.role:admin')->group(function () {
        Route::controller(ProtocolSettingsController::class)->prefix('protocol-settings')->as('protocol-settings.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('types', 'storeType')->name('types.store');
            Route::put('types/{protocolType}', 'updateType')->name('types.update');
            Route::delete('types/{protocolType}', 'destroyType')->name('types.destroy');
            Route::post('phases', 'storePhase')->name('phases.store');
            Route::put('phases/{protocolTypePhase}', 'updatePhase')->name('phases.update');
            Route::delete('phases/{protocolTypePhase}', 'destroyPhase')->name('phases.destroy');
            Route::post('phases/{protocolTypePhase}/weeks', 'storeWeek')->name('weeks.store');
            Route::delete('weeks/{protocolTypePhaseWeek}', 'destroyWeek')->name('weeks.destroy');
            Route::post('supplements', 'storeSupplement')->name('supplements.store');
            Route::put('supplements/{supplement}', 'updateSupplement')->name('supplements.update');
            Route::delete('supplements/{supplement}', 'destroySupplement')->name('supplements.destroy');
            Route::put('supplements/{supplement}/weeks/{protocolTypePhaseWeek}', 'storeSupplementWeek')->name('supplement-weeks.store');
            Route::delete('supplements/{supplement}/weeks/{protocolTypePhaseWeek}', 'destroySupplementWeek')->name('supplement-weeks.destroy');
        });

        Route::controller(SettingsController::class)->prefix('settings')->as('settings.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('admins', 'storeAdmin')->name('admins.store');
            Route::put('admins/{admin}', 'updateAdmin')->name('admins.update');
            Route::delete('admins/{admin}', 'destroyAdmin')->name('admins.destroy');
        });
    });
});
