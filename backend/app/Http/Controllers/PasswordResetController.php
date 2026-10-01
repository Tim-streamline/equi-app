<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\CustomerPasswordReset;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function requestLink(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => trim($request->input('email'))]);
        }
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->value('email') ?? mb_strtolower(trim($data['email']));
        Password::broker('users')->sendResetLink(['email' => $email, 'disabled_at' => null], function (User $user, string $token) {
            $user->notify(new CustomerPasswordReset($token));
        });

        // Same response for unknown, disabled and throttled accounts.
        return response()->json(['message' => 'Als dit e-mailadres bij ons bekend is, ontvang je een e-mail met een link om je wachtwoord opnieuw in te stellen.'])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $token): Response
    {
        return response()->view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $status = Password::broker('users')->reset($data + ['disabled_at' => null], function (User $user, string $password) {
            $user->password = $password;
            $user->remember_token = Str::random(60);
            $user->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'Deze resetlink is ongeldig of verlopen. Vraag een nieuwe link aan.'])->withInput($request->only('email'));
        }

        return redirect(rtrim(config('app.web_url'), '/').'/onboarding/welcome?passwordReset=1');
    }
}
