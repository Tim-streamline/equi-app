<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

/**
 * Verifies the Bearer JWT minted by PowerSyncAuthController. On success the
 * acting user's UUID is set on the request as `powersync_user_id`. Anything
 * touching synced tables behind /api/sync/* should depend on this middleware.
 */
class AuthenticatePowerSyncJwt
{
    public function handle(Request $request, Closure $next)
    {
        $auth = $request->bearerToken();
        if (! $auth) {
            return response()->json(['message' => 'Missing bearer token'], 401);
        }

        try {
            $publicPem = $this->readPublicKey();
            $decoded = JWT::decode($auth, new Key($publicPem, 'RS256'));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Invalid token',
                'detail' => $e->getMessage(),
            ], 401);
        }

        $expectedAudience = config('powersync.audience');
        $aud = is_array($decoded->aud ?? null) ? $decoded->aud : [$decoded->aud ?? null];
        if (! in_array($expectedAudience, $aud, true)) {
            return response()->json(['message' => 'Audience mismatch'], 401);
        }

        $request->attributes->set('powersync_user_id', $decoded->sub);
        $request->attributes->set('powersync_claims', (array) $decoded);

        return $next($request);
    }

    private function readPublicKey(): string
    {
        $path = config('powersync.public_key_path');
        if (! file_exists($path)) {
            abort(500, 'PowerSync public key not found. Run `php artisan powersync:generate-keys`.');
        }

        return file_get_contents($path);
    }
}
