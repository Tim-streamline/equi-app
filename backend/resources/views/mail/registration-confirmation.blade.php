<!doctype html>
<html lang="nl">
<head><meta charset="utf-8"><title>Bevestig je e-mailadres</title></head>
<body style="font-family: sans-serif; line-height: 1.6; color: #183333;">
    <p>Hallo {{ $name }},</p>
    <p>Bedankt voor je aanmelding bij Equi App. Klik op de onderstaande link om je e-mailadres te bevestigen:</p>
    <p><a href="{{ $confirmationUrl }}" style="display: inline-block; padding: 12px 20px; background: #127a79; color: #fff; border-radius: 8px; text-decoration: none;">Bevestig mijn e-mailadres</a></p>
    <p>De link is 15 minuten geldig. Laat het registratiescherm openstaan. Na het klikken op de link word je daar automatisch ingelogd, ook als je de e-mail op een ander apparaat opent.</p>
    <p>Werkt de knop niet? Kopieer deze link en plak hem in je browser:<br><a href="{{ $confirmationUrl }}">{{ $confirmationUrl }}</a></p>
    <p>Heb je geen account aangevraagd? Dan kun je deze e-mail negeren.</p>
    <p>Met vriendelijke groet,<br>Het Equi App-team</p>
</body>
</html>
