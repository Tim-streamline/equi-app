<script>
    import Field from './Field.svelte';
    import { Input, Select } from '$lib/components/ui';

    let { value = null, onchange, error = undefined } = $props();
    const config = $derived(value ?? {});

    function update(key, value) {
        onchange({ ...config, [key]: value });
    }

    function updateLabel(key, text) {
        const next = { ...config };
        if (text === '') delete next[key];
        else next[key] = text;
        onchange(next);
    }
</script>

<section class="space-y-5 rounded-xl border p-4 md:p-5" aria-label="Maand- en jaarinstellingen">
    <div class="space-y-1">
        <h3 class="font-semibold">Maand en jaar</h3>
        <p class="text-sm text-muted-foreground">Bepaal welke datums de klant kan invullen en welke teksten daarbij verschijnen.</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <Field label="Datum of periode">
            <Select aria-label="Datum of periode" value={config.mode ?? 'single'} options={[
                { value: 'single', label: 'Eén maand en jaar' },
                { value: 'range', label: 'Periode (van/tot)' },
            ]} onchange={event => update('mode', event.currentTarget.value)} />
        </Field>
        <Field label="Vroegste toegestane jaar" hint="Jaartallen vóór dit jaar worden niet geaccepteerd.">
            <Input aria-label="Vroegste toegestane jaar" type="number" min="1000" max="9999" step="1" required value={config.min_year ?? 1900}
                oninput={event => update('min_year', event.currentTarget.value === '' ? '' : Number(event.currentTarget.value))} />
        </Field>
    </div>

    <fieldset class="space-y-3">
        <legend class="mb-2 text-sm font-medium">Antwoordmogelijkheden</legend>
        <label class="flex cursor-pointer items-start gap-3">
            <input type="checkbox" class="mt-1 size-4 shrink-0 accent-primary" checked={config.allow_future === true} onchange={event => update('allow_future', event.currentTarget.checked)} />
            <span class="space-y-1"><span class="block text-sm font-medium">Toekomstige datums toestaan</span><span class="block text-xs text-muted-foreground">Bijvoorbeeld voor geplande afspraken.</span></span>
        </label>
        <label class="flex cursor-pointer items-start gap-3">
            <input type="checkbox" class="mt-1 size-4 shrink-0 accent-primary" checked={config.allow_year_only !== false} onchange={event => update('allow_year_only', event.currentTarget.checked)} />
            <span class="space-y-1"><span class="block text-sm font-medium">Alleen het jaar toestaan</span><span class="block text-xs text-muted-foreground">De klant kan ‘Maand onbekend’ kiezen en alleen het jaar invullen.</span></span>
        </label>
        <label class="flex cursor-pointer items-start gap-3">
            <input type="checkbox" class="mt-1 size-4 shrink-0 accent-primary" checked={config.allow_unknown !== false} onchange={event => update('allow_unknown', event.currentTarget.checked)} />
            <span class="space-y-1"><span class="block text-sm font-medium">‘Weet ik niet’ toestaan</span><span class="block text-xs text-muted-foreground">Dit telt als een ingevuld antwoord.</span></span>
        </label>
    </fieldset>

    <fieldset class="space-y-3 border-t pt-4">
        <legend class="px-1 text-sm font-medium">Teksten voor de klant</legend>
        <p class="text-xs text-muted-foreground">Laat een tekst leeg om de standaardtekst te gebruiken.</p>
        <div class="grid gap-4 md:grid-cols-2">
            <Field label="Label voor maand"><Input aria-label="Label voor maand" maxlength="100" value={config.month_label ?? ''} placeholder="Maand" oninput={event => updateLabel('month_label', event.currentTarget.value)} /></Field>
            <Field label="Label voor jaar"><Input aria-label="Label voor jaar" maxlength="100" value={config.year_label ?? ''} placeholder="Jaar" oninput={event => updateLabel('year_label', event.currentTarget.value)} /></Field>
            {#if config.allow_unknown !== false}
                <Field label="Tekst voor onbekende datum"><Input aria-label="Tekst voor onbekende datum" maxlength="100" value={config.unknown_label ?? ''} placeholder="Weet ik niet" oninput={event => updateLabel('unknown_label', event.currentTarget.value)} /></Field>
            {/if}
            {#if config.mode === 'range'}
                <Field label="Tekst voor doorlopende periode"><Input aria-label="Tekst voor doorlopende periode" maxlength="100" value={config.ongoing_label ?? ''} placeholder="Nog steeds" oninput={event => updateLabel('ongoing_label', event.currentTarget.value)} /></Field>
            {/if}
        </div>
    </fieldset>

    {#if error}<p role="alert" class="text-sm text-destructive">{error}</p>{/if}
</section>
