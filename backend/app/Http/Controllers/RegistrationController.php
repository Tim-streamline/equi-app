<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        try {
            [$user, $response] = DB::transaction(function () use ($data) {
                $user = User::create([
                    'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                    'avatar_initial' => mb_strtoupper(mb_substr($data['name'], 0, 1)),
                ]);

                return [$user, app(PowerSyncAuthController::class)->tokenResponse($user)];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Dit e-mailadres is al in gebruik. Log in met je bestaande account.']);
        }
        if ($request->routeIs('web-session.register')) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return $response->setStatusCode(201)->header('Cache-Control', 'no-store');
    }
}
