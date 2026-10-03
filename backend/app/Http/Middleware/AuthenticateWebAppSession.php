<?php

namespace App\Http\Middleware;

use App\Support\WebLogin;
use Closure;
use Illuminate\Http\Request;

class AuthenticateWebAppSession
{
    public function handle(Request $request, Closure $next)
    {
        $user = app(WebLogin::class)->user($request);
        abort_unless($user, 401);
        $request->attributes->set('powersync_user_id', (string) $user->id);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
