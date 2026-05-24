<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->prefix('admin')
                ->as('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['api/*']);

        // Inertia shares props with every web response and handles
        // asset-version / partial-reload negotiation.
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Role gate for admin pages — usage: ->middleware('admin.role:owner,admin').
        $middleware->alias([
            'admin.role' => \App\Http\Middleware\EnsureAdminRole::class,
        ]);

        // Unauthenticated admin requests land on the admin login screen.
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
