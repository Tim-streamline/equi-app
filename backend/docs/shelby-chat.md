# Shelby chat (27 oktober 2026)

Shelby antwoordt in de app met AI. De app stuurt een vraag naar `POST /api/shelby/messages`; de server voegt Shelby's instructie en de situatie van het paard toe, vraagt het antwoord aan de gekozen AI en slaat vraag en antwoord op. De berichten komen via PowerSync terug in de app.

## Instellen

Zet in de `.env` van de server:

- `SHELBY_PROVIDER`: `openai` of `anthropic`. Bepaalt wie in de app antwoordt.
- `SHELBY_OPENAI_API_KEY`: optioneel; zonder deze sleutel gebruikt Shelby de sleutel van de voerscanner.
- `SHELBY_ANTHROPIC_API_KEY`: nodig om Claude te gebruiken of te testen.
- `SHELBY_OPENAI_MODEL` en `SHELBY_ANTHROPIC_MODEL`: het model per aanbieder.
- `SHELBY_DAILY_LIMIT`: maximum aantal vragen per eigenaar per dag (standaard 40).

Draai daarna `php artisan migrate` (kolom `meta` op `chat_messages`).

## Shelby's gedrag aanpassen

De instructie staat in `resources/shelby/system-prompt.md` en volgt de Shelby-briefing. Wijzig de tekst daar; de volgende vraag gebruikt direct de nieuwe versie.

## Testen en vergelijken

In de beheeromgeving onder **Shelby chat** stel je één vraag aan alle ingestelde AI's tegelijk. De voorbeeldvragen uit de briefing staan klaar als knoppen. Testvragen worden niet als chat van een eigenaar opgeslagen.

## Regels in deze versie

- Elke dag begint per paard een nieuw gesprek. Eerdere dagen worden niet als geheugen meegestuurd.
- Elk bericht gaat eerst langs een snelle check op acute signalen. Is de AI dan niet bereikbaar, dan geeft Shelby een vast antwoord dat verwijst naar de dierenarts.
- Plus wordt hooguit één keer per gesprek genoemd en nooit bij een acuut signaal of aan een eigenaar die voor dit paard al Plus heeft. Alleen dan toont de app een knop "Bekijk Plus".
- Berichten kunnen niet via de synchronisatie vanaf een toestel worden geschreven; alleen de server maakt chatberichten aan.
- Nog niet in deze versie: foto's insturen, aanbevelingen uit de mediabibliotheek, 90 dagen chatgeschiedenis teruglezen en een beoordeling per antwoord.
