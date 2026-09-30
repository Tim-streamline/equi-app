<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ $confirmed ? 'E-mailadres bevestigd' : 'Bevestigingslink verlopen' }} | Equi App</title>
</head>
<body style="margin: 0; padding: 24px; background: #fbf8f3; color: #183333; font-family: sans-serif; line-height: 1.6;">
    <main style="max-width: 520px; margin: 12vh auto;">
        <p style="color: #127a79; font-weight: bold;">Equi App</p>
        @if ($confirmed)
            <h1>Je e-mailadres is bevestigd</h1>
            <p>Ga terug naar het registratiescherm in de app of browser. Je wordt daar automatisch ingelogd.</p>
            <p>Je kunt dit tabblad sluiten.</p>
        @else
            <h1>Deze link is niet meer geldig</h1>
            <p>De bevestigingslink is verlopen of vervangen door een nieuwe link. Ga terug naar het registratiescherm en vraag een nieuwe bevestigingsmail aan.</p>
        @endif
    </main>
</body>
</html>
