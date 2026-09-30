<?php

namespace App\Http\Controllers;

use App\Mail\RegistrationConfirmation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
            'email' => is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : $request->input('email'),
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', function ($attribute, $value, $fail) {
                if (User::whereRaw('lower(email) = ?', [$value])->exists()) {
                    $fail('Dit e-mailadres is al in gebruik. Log in met je bestaande account.');
                }
            }],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ], [
            'name.required' => 'Vul je naam in.',
            'email.required' => 'Vul je e-mailadres in.',
            'email.email' => 'Vul een geldig e-mailadres in.',
            'password.min' => 'Gebruik een wachtwoord van minimaal 8 tekens.',
            'password.confirmed' => 'De wachtwoorden komen niet overeen.',
        ]);

        // One email per address per minute, shared across native and browser routes.
        $limitKey = 'registration-mail:'.hash('sha256', $data['email']);
        if (! Cache::add($limitKey, true, 60)) {
            return response()->json(['message' => 'Wacht een minuut voordat je een nieuwe link aanvraagt.'], 429);
        }
        $token = Str::random(64);
        $uid = (string) Str::uuid();
        // Always use the configured public backend URL, never an untrusted request Host.
        $confirmationUrl = rtrim(config('app.url'), '/').'/registration/confirm/'.$uid;
        try {
            DB::transaction(function () use ($data, $token, $uid, $confirmationUrl) {
                // A resend replaces earlier links, including their proposed account details.
                DB::table('pending_registrations')->where('email', $data['email'])->delete();
                DB::table('pending_registrations')->insert([
                    'token_hash' => hash('sha256', $token),
                    'name' => $data['name'], 'email' => $data['email'],
                    'password_hash' => Hash::make($data['password']),
                    'uid_hash' => hash('sha256', $uid),
                    'expires_at' => now()->addMinutes(15), 'created_at' => now(),
                ]);
                Mail::to($data['email'])->send(new RegistrationConfirmation($data['name'], $confirmationUrl));
            });
        } catch (\Throwable $error) {
            Cache::forget($limitKey);
            report($error);

            return response()->json(['message' => 'De bevestigingsmail kon niet worden verstuurd. Je account is nog niet aangemaakt. Probeer het later opnieuw.'], 503);
        }

        return response()->json(['registration_token' => $token, 'email' => $data['email'], 'expires_in' => 900], 202)
            ->header('Cache-Control', 'no-store');
    }

    /** The email UID confirms ownership; it never grants a login session. */
    public function confirm(Request $request, string $uid): Response
    {
        $query = DB::table('pending_registrations')->where('uid_hash', hash('sha256', $uid))->where('expires_at', '>', now());
        $confirmed = DB::transaction(function () use ($query, $request) {
            $pending = $query->lockForUpdate()->first();
            if (! $pending) {
                return false;
            }
            if (! $pending->confirmed_at && ! $request->isMethod('HEAD')) {
                $query->update(['confirmed_at' => now()]);
            }

            return true;
        });

        return response()->view('auth.registration-confirmed', ['confirmed' => $confirmed], $confirmed ? 200 : 410)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /** Only the device that started registration holds this separate secret. */
    public function status(Request $request): JsonResponse
    {
        $token = $this->registrationToken($request);
        $pending = DB::table('pending_registrations')->where('token_hash', hash('sha256', $token))->first();
        $status = ! $pending || now()->greaterThanOrEqualTo($pending->expires_at)
            ? 'expired' : ($pending->confirmed_at ? 'confirmed' : 'pending');

        return response()->json(['status' => $status])->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request): JsonResponse
    {
        $token = $this->registrationToken($request);
        try {
            $result = DB::transaction(function () use ($token) {
                $query = DB::table('pending_registrations')->where('token_hash', hash('sha256', $token));
                $pending = $query->lockForUpdate()->first();
                if (! $pending || now()->greaterThanOrEqualTo($pending->expires_at)) {
                    return 'Deze bevestigingslink is verlopen of niet meer geldig. Vraag een nieuwe link aan.';
                }
                if (! $pending->confirmed_at) {
                    return 'Bevestig eerst je e-mailadres via de link in je e-mail.';
                }
                // Retrying a lost response must not create a second account or lose the login.
                if ($pending->user_id) {
                    $user = User::findOrFail($pending->user_id);

                    return [$user, app(PowerSyncAuthController::class)->tokenResponse($user)];
                }
                if (User::whereRaw('lower(email) = ?', [$pending->email])->exists()) {
                    $query->delete();

                    return 'Dit e-mailadres is al in gebruik. Log in met je bestaande account.';
                }
                $user = new User([
                    'name' => $pending->name, 'email' => $pending->email, 'password' => $pending->password_hash,
                    'avatar_initial' => mb_strtoupper(mb_substr($pending->name, 0, 1)),
                ]);
                $user->email_verified_at = $pending->confirmed_at;
                $user->save();
                $response = app(PowerSyncAuthController::class)->tokenResponse($user);
                $query->update(['user_id' => $user->id, 'password_hash' => '', 'expires_at' => now()->addMinutes(2)]);

                return [$user, $response];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Dit e-mailadres is al in gebruik. Log in met je bestaande account.']);
        }
        if (is_string($result)) {
            throw ValidationException::withMessages(['registration_token' => $result]);
        }
        [$user, $response] = $result;
        if ($request->routeIs('web-session.register.complete')) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return $response->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    private function registrationToken(Request $request): string
    {
        return $request->validate([
            'registration_token' => ['required', 'string', 'size:64'],
        ], ['registration_token.*' => 'Vraag een nieuwe bevestigingslink aan.'])['registration_token'];
    }
}
