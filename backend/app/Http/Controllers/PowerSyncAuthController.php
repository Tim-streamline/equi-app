<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\DeviceTokens;
use App\Support\MasterKeyLogin;
use App\Support\SubscriptionAccess;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
     * of the sync service. With a `device_name` it also returns a device
     * token for `token()`; app versions before device tokens instead call
     * this from `fetchCredentials()` whenever they need a fresh JWT.
     */
    public function login(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:100',
        ]);

        $user = User::whereRaw('lower(email) = ?', [$data['email']])->first();

        $support = app(MasterKeyLogin::class);
        $masterKey = $support->matches($data['password']);
        if (! $user || (! $masterKey && ! Hash::check($data['password'], $user->password))) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $fingerprint = $masterKey ? $support->fingerprint() : null;
        $response = $this->tokenResponse($user, null, $fingerprint);
        if ($masterKey) {
            $support->audit($request, $user);
        }

        return app(DeviceTokens::class)->issue($response, $request, $user, $fingerprint);
    }

    /**
     * POST /api/auth/token
     *
     * Trades the app's device token for a fresh PowerSync JWT. Called from
     * `fetchCredentials()`; a 401 means the app must sign in again.
     */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate(['device_token' => 'required|string|max:255']);
        $tokens = app(DeviceTokens::class);
        $token = $tokens->find($data['device_token']);
        $fingerprint = $token ? $tokens->supportFingerprint($token) : null;
        if (! $token || ($fingerprint !== null && ! app(MasterKeyLogin::class)->validFingerprint($fingerprint))) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $response = $this->tokenResponse($token->tokenable, $fingerprint === null ? null : $token->expires_at->timestamp, $fingerprint);
        $tokens->used($token);

        return $response->header('Cache-Control', 'no-store');
    }

    /** POST /api/auth/logout — revokes this device's token; unknown tokens are ignored. */
    public function logout(Request $request): Response
    {
        $token = $request->input('device_token');
        if (is_string($token)) {
            app(DeviceTokens::class)->revoke($token);
        }

        return response()->noContent();
    }

    public function tokenResponse(User $user, ?int $expiresAt = null, ?string $supportFingerprint = null): JsonResponse
    {
        abort_if($user->disabled_at, 403, 'Dit account is niet beschikbaar.');
        $ttl = (int) config('powersync.token_ttl');
        $subscription = app(SubscriptionAccess::class)->active($user->id);
        if ($subscription) {
            foreach (['paid_through', 'ended_at', 'cancelled_at'] as $field) {
                if ($subscription->$field) {
                    $ttl = min($ttl, max(1, $subscription->$field->timestamp - now()->timestamp));
                }
            }
        }
        if ($expiresAt !== null) {
            $ttl = min($ttl, $expiresAt - now()->timestamp);
            abort_if($ttl <= 0, 401);
        }
        if ($supportFingerprint !== null) {
            $ttl = min($ttl, MasterKeyLogin::TOKEN_SECONDS);
        }

        return response()->json([
            'endpoint' => config('powersync.service_url'),
            'token' => $this->mintToken($user, $ttl, $supportFingerprint),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_initial' => $user->avatar_initial,
            ],
            'expires_in' => $ttl,
        ]);
    }

    private function mintToken(User $user, int $ttl, ?string $supportFingerprint = null): string
    {
        $now = now()->timestamp;
        $payload = [
            'iss' => config('powersync.issuer'),
            'aud' => config('powersync.audience'),
            'sub' => (string) $user->id,
            'subscribed' => app(SubscriptionAccess::class)->active($user->id) ? 1 : 0,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];
        if ($supportFingerprint !== null) {
            $payload['support_key'] = $supportFingerprint;
        }

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
