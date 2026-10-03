<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer"><title>Nieuw wachtwoord · {{ config('app.brand_name') }}</title>
    <style>
        * { box-sizing: border-box; } body { margin: 0; min-height: 100dvh; display: grid; place-items: center; background: #105c5b; color: white; font-family: system-ui, sans-serif; padding: 28px; }
        main { width: 100%; max-width: 420px; } h1 { font-size: 28px; } p { line-height: 1.6; color: #99e8df; } label { display: block; margin-top: 18px; font-size: 14px; } input { width: 100%; margin-top: 8px; border: 1px solid transparent; border-radius: 24px; background: #ffffff1a; color: white; padding: 14px 18px; font: inherit; } input:focus { outline: 2px solid #99e8df; } button { width: 100%; margin-top: 24px; border: 0; border-radius: 24px; padding: 14px; font: inherit; font-weight: 600; background: #18bab0; color: white; cursor: pointer; } a { color: #99e8df; } .error { color: #fca5a5; }
    </style>
</head>
<body><main>
    <p>{{ config('app.brand_name') }} · by De Paardentherapeut</p><h1>Nieuw wachtwoord instellen</h1>
    <p>Kies een nieuw wachtwoord van minimaal 8 tekens.</p>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('password.update') }}">
        @csrf <input type="hidden" name="token" value="{{ $token }}">
        <label for="email">E-mailadres</label><input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required>
        <label for="password">Nieuw wachtwoord</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
        <label for="confirmation">Herhaal nieuw wachtwoord</label><input id="confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
        <button type="submit">Wachtwoord opslaan</button>
    </form>
    <p><a href="{{ rtrim(config('app.web_url'), '/') }}/onboarding/forgot-password">Nieuwe resetlink aanvragen</a></p>
</main></body></html>
