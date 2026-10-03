<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class WebLogin
{
    public const MINUTES = 30 * 24 * 60;

    public const SESSION_KEY = 'web_login';

    public function cookieName(): string
    {
        return config('session.cookie').'_web_login';
    }

    /** Called after login or a one-time legacy session upgrade, never on renewal. */
    public function start(Request $request, User $user): void
    {
        $login = [
            'user_id' => (string) $user->id,
            'expires_at' => now()->addMinutes(self::MINUTES)->timestamp,
            'token_hash' => hash('sha256', (string) $user->getRememberToken()),
        ];
        $request->session()->put(self::SESSION_KEY, $login);
        // Laravel encrypts this HttpOnly cookie. It preserves the original
        // deadline when the shorter server session expires or is collected.
        Cookie::queue(Cookie::make($this->cookieName(), json_encode($login), self::MINUTES));
    }

    public function user(Request $request): ?User
    {
        $guard = Auth::guard('web');
        // Capture this before user(): remember-cookie restoration creates a
        // session too, but cannot qualify as an existing legacy session.
        $existingSession = $request->session()->get($guard->getName());
        $hasLoginMetadata = $request->session()->exists(self::SESSION_KEY)
            || $request->cookies->has($this->cookieName());
        $login = $request->session()->get(self::SESSION_KEY)
            ?? json_decode((string) $request->cookie($this->cookieName()), true);
        $user = $guard->user();
        if ($user && ! $user->disabled_at && ! $hasLoginMetadata
            && (string) $existingSession === (string) $user->id && ! $guard->viaRemember()) {
            // Old sessions have no original login timestamp. Give a valid
            // session one fixed 30-day period from this transparent upgrade.
            $guard->setRememberDuration(self::MINUTES)->login($user, true);
            $this->start($request, $user);
            $login = $request->session()->get(self::SESSION_KEY);
        }
        if (! $user || $user->disabled_at || ! is_array($login)
            || ($login['user_id'] ?? null) !== (string) $user->id
            || ($login['expires_at'] ?? 0) <= now()->timestamp
            || ! hash_equals(hash('sha256', (string) $user->getRememberToken()), (string) ($login['token_hash'] ?? ''))) {
            if ($user || $login) {
                Auth::guard('web')->logoutCurrentDevice();
                $this->forget($request);
            }

            return null;
        }

        $request->session()->put(self::SESSION_KEY, $login);

        return $user;
    }

    public function expiresAt(Request $request): int
    {
        return (int) $request->session()->get(self::SESSION_KEY.'.expires_at', 0);
    }

    public function forget(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
        Cookie::queue(Cookie::forget($this->cookieName()));
    }
}
