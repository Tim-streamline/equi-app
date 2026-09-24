<?php

namespace App\Http\Controllers;

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
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::guard('web')->attempt([...$credentials, 'disabled_at' => null])) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }
        $request->session()->regenerate();

        return $this->token($request);
    }

    public function token(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();
        abort_unless($user && ! $user->disabled_at, 401);

        return app(PowerSyncAuthController::class)->tokenResponse($user)->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        // Preserve the independent admin guard, while invalidating this session ID.
        $request->session()->migrate(true);
        $request->session()->regenerateToken();

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
