<script>
    let { ocr = null, recognition = null, title = 'Tekstherkenning (OCR)' } = $props();
    const text = $derived(ocr?.text ?? '');
    const confidence = $derived(Number.isFinite(ocr?.confidence) ? ocr.confidence.toLocaleString('nl-NL', { maximumFractionDigits: 1 }) : null);
    const outcomes = {
        candidate: 'Mogelijk bestaand product gevonden.',
        changed: 'De samenstelling is mogelijk gewijzigd; controle van het etiket is nodig.',
        unknown: 'Geen eenduidige productmatch gevonden.',
        uncertain: 'Onvoldoende zekerheid om een product te herkennen.',
        unreadable: 'De tekst op de foto kon niet worden gelezen.',
    };
</script>

<section aria-label={title} class="space-y-3 border-t pt-4">
    <div>
        <h4 class="font-semibold">{title}</h4>
        <p class="text-sm text-muted-foreground">Automatisch gelezen van deze foto. Controleer de tekst aan de hand van het originele etiket.</p>
    </div>
    {#if ocr?.engine === 'unavailable'}
        <p class="text-sm text-destructive">Tekstherkenning was niet beschikbaar voor deze foto. Er is geen OCR-tekst opgeslagen.</p>
    {:else if text.trim()}
        <dl class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
            {#if confidence !== null}
                <div><dt class="text-muted-foreground">Gemiddelde OCR-zekerheid</dt><dd class="font-medium">{confidence} / 100</dd></div>
            {/if}
            {#if Number.isFinite(ocr?.words)}
                <div><dt class="text-muted-foreground">Gelezen woorden</dt><dd class="font-medium">{ocr.words}</dd></div>
            {/if}
            {#if Number.isFinite(ocr?.low_confidence_words)}
                <div><dt class="text-muted-foreground">Woorden met lage zekerheid</dt><dd class="font-medium">{ocr.low_confidence_words}</dd></div>
            {/if}
        </dl>
        <p class="text-xs text-muted-foreground">De OCR-zekerheid gaat over het lezen van tekst, niet over de juistheid van een productmatch.</p>
        <div class="space-y-1">
            <h5 class="text-sm font-medium">Gelezen tekst</h5>
            <p class="whitespace-pre-wrap break-words rounded-lg bg-muted/50 p-3 text-sm leading-relaxed">{text}</p>
        </div>
    {:else if ocr}
        <p class="text-sm text-muted-foreground">Tekstherkenning is uitgevoerd, maar heeft geen leesbare tekst opgeleverd.</p>
    {:else}
        <p class="text-sm text-muted-foreground">Er is nog geen OCR-resultaat opgeslagen voor deze foto.</p>
    {/if}
    {#if recognition?.decision}
        <div class="space-y-1 text-sm">
            <h5 class="font-medium">Productherkenning</h5>
            <p>{outcomes[recognition.decision] ?? 'Geen herkenningsuitkomst beschikbaar.'}</p>
            {#if recognition.decision === 'candidate' && (recognition.brand || recognition.name)}
                <p class="font-medium">{[recognition.brand, recognition.name].filter(Boolean).join(' · ')}</p>
            {/if}
            {#if recognition.owner_reported_change}
                <p>De gebruiker heeft aangegeven dat het etiket afwijkt of aanvullende controle nodig is.</p>
            {/if}
        </div>
    {/if}
</section>
