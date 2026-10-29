<script>
    import SubmissionOcr from './SubmissionOcr.svelte';
    let { ocr = null, luna = null, recognition = null, nutrientOptions = {} } = $props();
    const data = $derived(luna?.data);
    const errorMessages = {
        reader_not_configured: 'Luna is nog niet ingesteld op deze server. De API-sleutel ontbreekt.',
        reader_incomplete: 'Luna heeft de analyse niet volledig afgerond.',
        reader_invalid_output: 'Het antwoord van Luna kon niet veilig worden verwerkt.',
        reader_unavailable: 'Luna kon deze foto niet uitlezen. Probeer opnieuw.',
    };
</script>

<div class="grid items-start gap-5 lg:grid-cols-2">
    <SubmissionOcr {ocr} {recognition} title="OCR · Tesseract" />
    <section aria-label="Luna-analyse" class="min-w-0 space-y-3 border-t pt-4">
        <div><h4 class="font-semibold">Luna · GPT-6</h4><p class="text-sm text-muted-foreground">Bron voor de automatische productgegevens. Controleer de uitkomst op het originele etiket.</p></div>
        {#if luna?.status === 'failed'}
            <p role="alert" class="text-sm text-destructive">{errorMessages[luna.error_code] ?? 'De Luna-analyse is niet gelukt.'}</p>
        {:else if data}
            <div class="space-y-1"><h5 class="text-sm font-medium">Gelezen tekst</h5><p class="whitespace-pre-wrap break-words rounded-lg bg-muted/50 p-3 text-sm leading-relaxed">{data.raw_text || data.composition_text || 'Geen leesbare tekst gevonden.'}</p></div>
            {#if data.brand || data.name}<p class="text-sm"><strong>Product:</strong> {[data.brand, data.name].filter(Boolean).join(' · ')}</p>{/if}
            {#if data.ingredients?.length}
                <div><h5 class="text-sm font-medium">Ingrediënten en toevoegingen</h5><ol class="mt-2 list-decimal space-y-2 pl-5 text-sm">{#each data.ingredients as row}<li><strong>{row.label}</strong>{row.amount ? ` · ${row.amount}` : ''}<span class="text-muted-foreground"> · {row.confidence === 'high' ? 'Duidelijk gelezen' : 'Onzeker'}</span>{#if row.evidence}<p class="break-words text-muted-foreground">Bron: {row.evidence}</p>{/if}</li>{/each}</ol></div>
            {/if}
            {#if data.nutrients?.length}
                <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="mb-2 text-left font-medium">Voedingswaarden</caption><thead><tr class="border-b"><th class="py-2 pr-3">Waarde</th><th>Uitgelezen</th></tr></thead><tbody>{#each data.nutrients as row}<tr class="border-b align-top"><td class="py-2 pr-3">{nutrientOptions[row.code] ?? row.code}</td><td class="py-2">{({less_than: '<', greater_than: '>', minimum: 'min.', maximum: 'max.'})[row.qualifier] ?? ''} {row.value ?? 'Onleesbaar'} {row.unit}<p class="text-xs text-muted-foreground">{({product: 'Per kg product', dry_matter: 'Droge stof', unknown: 'Basis onbekend'})[row.basis]} · {row.confidence === 'high' ? 'Duidelijk gelezen' : 'Onzeker'}</p>{#if row.source}<p class="break-words text-xs text-muted-foreground">Bron: {row.source}</p>{/if}</td></tr>{/each}</tbody></table></div>
            {/if}
            {#if data.unreadable_fields?.length}<div class="text-sm"><h5 class="font-medium">Niet leesbaar / ontbreekt</h5><ul class="list-disc pl-5">{#each data.unreadable_fields as field}<li>{field}</li>{/each}</ul></div>{/if}
        {:else}<p class="text-sm text-muted-foreground">Er is nog geen Luna-resultaat opgeslagen voor deze foto.</p>{/if}
        {#if luna}
            <p class="text-xs text-muted-foreground">{luna.model} · promptversie {luna.prompt_version ?? '?'}{luna.processed_at ? ` · ${new Date(luna.processed_at).toLocaleString('nl-NL')}` : ''}</p>
            {#if luna.usage}<p class="text-xs text-muted-foreground">Tokens: {luna.usage.input_tokens ?? '?'} invoer · {luna.usage.output_tokens ?? '?'} uitvoer</p>{/if}
            {#if luna.prompt}<details><summary class="cursor-pointer text-sm">Gebruikte analyseprompt</summary><p class="mt-2 whitespace-pre-wrap break-words text-xs">{luna.prompt}</p></details>{/if}
        {/if}
    </section>
</div>
