<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Long-lived app sessions. The app keeps a device token in the phone's secure
 * storage and exchanges it for short-lived PowerSync JWTs, so it never stores
 * the customer's password. The token itself grants no API access.
 */
class DeviceTokens
{
    /** Customer tokens expire after this many days without use. */
    public const DAYS = 90;

    private const ABILITY = 'powersync';

    private const SUPPORT = 'support:';

    /** Adds a new device token when the app asks for one; older app versions do not send `device_name`. */
    public function issue(JsonResponse $response, Request $request, User $user, ?string $supportFingerprint = null): JsonResponse
    {
        $name = $request->input('device_name');
        if (! is_string($name) || trim($name) === '') {
            return $response;
        }
        // Support access ends after the same hour as in the browser and is never extended.
        $token = $user->createToken(
            mb_substr(trim($name), 0, 100),
            $supportFingerprint === null ? [self::ABILITY] : [self::ABILITY, self::SUPPORT.$supportFingerprint],
            $supportFingerprint === null ? now()->addDays(self::DAYS) : now()->addSeconds(MasterKeyLogin::SESSION_SECONDS),
        );

        return $response->setData([...$response->getData(true), 'device_token' => $token->plainTextToken]);
    }

    /** The id of the device token a response from issue() carries, or null when it has none. */
    public function issuedId(JsonResponse $response): ?int
    {
        $plainText = $response->getData(true)['device_token'] ?? null;

        return is_string($plainText) ? (int) strstr($plainText, '|', true) : null;
    }

    /** The unexpired device token for a customer account, or null. */
    public function find(string $plainText): ?PersonalAccessToken
    {
        $token = $this->lookup($plainText);
        if (! $token || ! $token->can(self::ABILITY) || ! $token->tokenable instanceof User
            || ($token->expires_at && $token->expires_at->isPast())) {
            return null;
        }

        return $token;
    }

    public function supportFingerprint(PersonalAccessToken $token): ?string
    {
        foreach ($token->abilities ?? [] as $ability) {
            if (is_string($ability) && str_starts_with($ability, self::SUPPORT)) {
                return substr($ability, strlen(self::SUPPORT));
            }
        }

        return null;
    }

    /** Records use and moves a customer token's expiry forward; support tokens keep their deadline. */
    public function used(PersonalAccessToken $token): void
    {
        $token->forceFill(['last_used_at' => now()]);
        if ($this->supportFingerprint($token) === null) {
            $token->expires_at = now()->addDays(self::DAYS);
        }
        $token->save();
    }

    public function revoke(string $plainText): void
    {
        $this->lookup($plainText)?->delete();
    }

    /** Only well-formed tokens reach the query: Sanctum looks up the part before `|` as the numeric id. */
    private function lookup(string $plainText): ?PersonalAccessToken
    {
        return preg_match('/\A\d{1,18}\|[A-Za-z0-9]{40,100}\z/', $plainText) ? PersonalAccessToken::findToken($plainText) : null;
    }

    /** Signs the account out on every device, e.g. after a password reset. */
    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }
}
