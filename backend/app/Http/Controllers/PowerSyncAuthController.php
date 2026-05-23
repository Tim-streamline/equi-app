<?php

namespace App\Http\Controllers;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PowerSyncAuthController extends Controller
{
    /**
     * GET /.well-known/jwks.json
     *
     * Public endpoint consumed by the PowerSync service to verify the JWTs
     * we mint in `token()`. We expose just the public half of the RSA pair
     * as a single RS256 JWKS entry.
     */
    public function jwks(): JsonResponse
    {
        $publicPem = $this->readPublicKey();

        $details = openssl_pkey_get_details(openssl_pkey_get_public($publicPem));
        $n = $this->base64UrlEncode($details['rsa']['n']);
        $e = $this->base64UrlEncode($details['rsa']['e']);

        return response()->json([
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => config('powersync.key_id'),
                'n' => $n,
                'e' => $e,
            ]],
        ]);
    }

    /**
     * POST /api/auth/login
     *
     * Trades email + password for a short-lived PowerSync JWT plus the URL
     * of the sync service. The Expo BackendConnector calls this from
     * `fetchCredentials()` whenever it needs a fresh token.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return response()->json([
            'endpoint' => config('powersync.service_url'),
            'token' => $this->mintToken($user),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_initial' => $user->avatar_initial,
            ],
            'expires_in' => config('powersync.token_ttl'),
        ]);
    }

    private function mintToken(User $user): string
    {
        $now = time();
        $payload = [
            'iss' => config('powersync.issuer'),
            'aud' => config('powersync.audience'),
            'sub' => (string) $user->id,
            'iat' => $now,
            'exp' => $now + (int) config('powersync.token_ttl'),
        ];

        return JWT::encode(
            $payload,
            $this->readPrivateKey(),
            'RS256',
            config('powersync.key_id'),
        );
    }

    private function readPrivateKey(): string
    {
        $path = config('powersync.private_key_path');
        if (! file_exists($path)) {
            abort(500, 'PowerSync private key not found at '.$path.'. Run `php artisan powersync:generate-keys`.');
        }

        return file_get_contents($path);
    }

    private function readPublicKey(): string
    {
        $path = config('powersync.public_key_path');
        if (! file_exists($path)) {
            abort(500, 'PowerSync public key not found at '.$path.'. Run `php artisan powersync:generate-keys`.');
        }

        return file_get_contents($path);
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
