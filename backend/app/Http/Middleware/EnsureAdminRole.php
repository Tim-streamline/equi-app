<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route to one or more admin role scopes. `owner` always passes.
 * Usage: ->middleware('admin.role:content_editor,moderator')
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->user('admin');

        if (! $admin || ! $admin->hasRole(...$roles)) {
            abort(403, 'You do not have access to this section.');
        }

        return $next($request);
    }
}
