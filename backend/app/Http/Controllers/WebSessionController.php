<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MasterKeyLogin;
use App\Support\WebLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WebSessionController extends Controller
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['token' => $request->session()->token()])->header('Cache-Control', 'no-store');
    }

    public function login(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $support = app(MasterKeyLogin::class);
        if ($support->matches($credentials['password'])) {
            $user = User::whereRaw('lower(email) = ?', [$credentials['email']])->whereNull('disabled_at')->first();
            if (! $user) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }
            $support->audit($request, $user);
            // Clear this browser's old remember cookie without rotating the customer's token.
            Auth::guard('web')->logoutCurrentDevice();
            Auth::guard('web')->login($user, false);
            $request->session()->regenerate();
            app(WebLogin::class)->startSupport($request, $user);

            return $this->token($request);
        }
        $credentials['email'] = User::whereRaw('lower(email) = ?', [$credentials['email']])->value('email') ?? $credentials['email'];
        if (! Auth::guard('web')->setRememberDuration(WebLogin::MINUTES)->attempt([...$credentials, 'disabled_at' => null], true)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }
        $request->session()->regenerate();
        app(WebLogin::class)->start($request, Auth::guard('web')->user());

        return $this->token($request);
    }

    public function token(Request $request): JsonResponse
    {
        $login = app(WebLogin::class);
        $user = $login->user($request);
        abort_unless($user, 401);

        return app(PowerSyncAuthController::class)->tokenResponse($user, $login->expiresAt($request),
            $request->session()->get(WebLogin::SESSION_KEY.'.support_key'))->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->session()->has(WebLogin::SESSION_KEY.'.support_key')) {
            Auth::guard('web')->logoutCurrentDevice();
        } else {
            Auth::guard('web')->logout();
        }
        app(WebLogin::class)->forget($request);
        // Preserve the independent admin guard, while invalidating this session ID.
        $request->session()->migrate(true);
        $request->session()->regenerateToken();

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
