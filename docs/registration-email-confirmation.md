# Registratie met een bevestigingslink

Nieuwe gebruikers krijgen een Nederlandstalige plain-text e-mail met een link met een willekeurige UUID. De registratie-e-mail bevat geen HTML-deel: in de staging-afleverproef van 2 oktober 2026 kwam de plain-text variant met de publieke bevestigingslink aan, terwijl de HTML-variant met diezelfde link niet aankwam. Welke provider of filter dit veroorzaakt is nog niet bevestigd. Klikken bevestigt het e-mailadres en toont een Nederlandse bevestigingspagina. Het oorspronkelijke registratiescherm controleert iedere drie seconden de status en maakt na bevestiging automatisch het account en de sessie aan. Dit werkt ook als de e-mail op een ander apparaat wordt geopend. Bestaande accounts blijven werken.

De e-maillink en het wachtende apparaat gebruiken verschillende geheimen:

- `POST /api/auth/register` en `POST /web-session/register` geven HTTP 202 met `registration_token`, `email` en `expires_in` (900 seconden). Dit is nog geen ingelogde sessie. Het token blijft in het geheugen van het registratiescherm.
- De e-mail bevat uitsluitend de link `${APP_URL}/registration/confirm/{uid}`. De UUID staat niet in het API-antwoord en verleent geen sessie. De pagina vraagt geen extra handmatige actie en geeft geen accountgegevens terug.
- De corresponderende `/register/status` route accepteert het private `registration_token` en geeft `pending`, `confirmed` of `expired` terug. Browseraanvragen gebruiken steeds een actueel CSRF-token.
- Na `confirmed` voltooit `/register/complete` automatisch de registratie. Het account krijgt `email_verified_at` en de bestaande HTTP 201 loginrespons. Herhalen binnen twee minuten na voltooiing herstelt een eventueel verloren antwoord zonder dubbele gebruiker of dubbele credits. Daarna vervalt ook dit token.

Maximaal één e-mail per adres per minuut. De link verloopt na 15 minuten. Opnieuw versturen vervangt zowel de oude link als het private token. De scheduler ruimt verlopen aanvragen ieder uur op. Wachtwoord, UUID en aanvraagtoken worden gehasht opgeslagen; de wachtwoordhash in de aanvraag wordt na voltooiing gewist. Bij mailfouten ontstaat geen account. Tijdelijke netwerkfouten worden automatisch opnieuw geprobeerd. Verlaten van het scherm, opnieuw versturen en wijzigen van het e-mailadres stoppen de vorige statuscontrole.

## Uitrollen

- Voer beide migraties uit: `2026_09_29_220000_create_pending_registrations_table.php` en `2026_09_29_230000_use_registration_confirmation_links.php`. De tweede verwijdert alleen oude, tijdelijke code-aanvragen. Bestaande gebruikers blijven behouden.
- Publiceer backend en bijgewerkte native/webclients samen; de eerdere code-invoer en `/register/verify` route zijn vervangen.
- Stel `APP_URL` in op de publieke backend-URL die ook vanaf het apparaat waarop de e-mail wordt geopend bereikbaar is.
- Vul de bestaande TransIP SMTP-inloggegevens en een geldig afzenderadres in, vernieuw de Laravel-configuratie en herlaad Octane. Zonder deze gegevens kan geen bevestigingsmail worden verstuurd en dus geen nieuw account worden aangemaakt.
- Laat de Laravel-scheduler draaien voor het opruimen van verlopen aanvragen.

Het registratiescherm moet open blijven. Na sluiten of herladen kan een onvoltooide registratie opnieuw worden gestart; het wachtwoord wordt niet voor deze wachtstap in browseropslag bewaard.

Regressietests dekken de echte API-routes en mailtransportketen, Nederlandse plain-text zonder HTML-deel, onafhankelijke UID en wacht-token, CSRF en sessierotatie, verlopen/vervangen links, herhaalde bevestiging/voltooiing, mailfouten, accountconflicten en staging-tegoed. Frontendtests voeren de gedeelde wachtinterface uit met automatische aanmelding, netwerkherstel, opnieuw versturen en annulering van late antwoorden.

Na een succesvolle overdracht aan het mailtransport schrijft Laravel `registration_mail_handed_off` naar het applicatielog, met het bericht-ID en alleen het domein van de ontvanger. Het log bevat geen volledige e-mailadressen, namen, bevestigingslinks of SMTP-inloggegevens. Het bericht-ID kan bij TransIP worden gebruikt om de verdere aflevering te onderzoeken. Een succesvolle SMTP-overdracht bewijst geen aflevering in de inbox. Better Stack registreert uitzonderingen; latere filtering of afwijzing door de mailprovider verschijnt daar niet vanzelf.
