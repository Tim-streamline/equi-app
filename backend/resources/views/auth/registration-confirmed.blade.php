<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $confirmed ? 'E-mailadres bevestigd' : 'Bevestigingslink verlopen' }} | {{ config('app.brand_name') }}</title>
</head>
<body style="margin: 0; padding: 24px; background: #fbf8f3; color: #183333; font-family: sans-serif; line-height: 1.6;">
    <main style="max-width: 520px; margin: 12vh auto;">
        <p style="color: #127a79; font-weight: bold;">{{ config('app.brand_name') }}</p>
        @if ($confirmed)
            <h1>Je e-mailadres is bevestigd</h1>
            <p>Je account is aangemaakt. Je kunt nu inloggen met je e-mailadres en wachtwoord.</p>
            <div id="browser-confirmation">
                <p>Staat het registratiescherm nog open in de app of browser? Dan word je daar automatisch ingelogd.</p>
                <p>Je kunt dit tabblad sluiten.</p>
            </div>
            <p id="pwa-confirmation" hidden>
                <a href="{{ config('app.web_url') }}" style="display: inline-block; padding: 12px 20px; background: #127a79; color: #fff; border-radius: 8px; text-decoration: none;">Open EquiApp</a>
            </p>
            <script>
                if (window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true) {
                    document.getElementById('browser-confirmation').hidden = true;
                    document.getElementById('pwa-confirmation').hidden = false;
                }
            </script>
        @else
            <h1>Deze link is niet meer geldig</h1>
            <p>De bevestigingslink is verlopen of vervangen door een nieuwe link. Ga terug naar het registratiescherm en vraag een nieuwe bevestigingsmail aan.</p>
        @endif
    </main>
</body>
</html>
