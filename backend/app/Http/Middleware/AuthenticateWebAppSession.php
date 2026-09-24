<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateWebAppSession
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('web')->user();
        abort_unless($user && ! $user->disabled_at, 401);
        $request->attributes->set('powersync_user_id', (string) $user->id);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
