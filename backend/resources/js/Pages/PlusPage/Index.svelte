<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Field from '$lib/components/Field.svelte';
    import { Button, Input, Textarea } from '$lib/components/ui';
    import { useForm } from '@inertiajs/svelte';
    import { prepareHeroPhoto } from '$lib/hero-photo.js';
    let { content, heroImageUrl, portraitImageUrl, protocolPriceCents } = $props();
    const form = useForm({ protocol_price_cents: protocolPriceCents, content: structuredClone(content), hero: null, portrait: null, remove_portrait: false });
    let preparingHero = $state(false);
    let heroPreview = $state(null);
    let heroSelection = 0;
    $effect(() => {
        const file = $form.hero;
        const url = file ? URL.createObjectURL(file) : null;
        heroPreview = url;
        return () => { if (url) URL.revokeObjectURL(url); };
    });
    async function selectHero(event) {
        const selection = ++heroSelection;
        const file = event.currentTarget.files?.[0] ?? null;
        $form.hero = null;
        $form.clearErrors('hero');
        preparingHero = !!file;
        try {
            const prepared = await prepareHeroPhoto(file);
            if (selection === heroSelection) $form.hero = prepared;
        } catch {
            if (selection === heroSelection) $form.setError('hero', 'De foto kon niet worden verwerkt. Kies een JPG-, PNG- of WebP-foto van maximaal 10 MB.');
        } finally {
            if (selection === heroSelection) preparingHero = false;
        }
    }
    const sections = [
        ['EquiBoost-blok op Home', ['homeTitle', 'homeBody', 'homeButton']],
        ['Intro en prijs', ['pageTitle', 'heroLabel', 'heroTitle', 'heroBody', 'period', 'heroButton']],
        ['Hoe het werkt', ['stepsTitle', 'stepsButton']],
        ['Shelley', ['guideTitle', 'guideBody', 'guideSignature']],
        ['Reviews en prijsblok', ['reviewsTitle', 'pricePeriod', 'priceButton', 'priceNote']],
        ['Afsluiter en bevestiging', ['closingTitle', 'confirmationTitle', 'confirmationPlan', 'confirmationProtocol', 'confirmationButton', 'welcomeTitle', 'welcomeBody']],
    ];
    const labels = { heroTitle: 'Kop', heroBody: 'Introductie', price: 'Prijs', period: 'Periode', heroButton: 'Eerste knop', stepsTitle: 'Titel stappen', stepsButton: 'Knop na stappen', guideTitle: 'Titel begeleider', guideBody: 'Over Shelley', guideSignature: 'Ondertekening', reviewsTitle: 'Titel reviews', priceButton: 'Knop prijsblok', priceNote: 'Regel onder prijs', closingTitle: 'Afsluitende kop', confirmationTitle: 'Titel bevestiging', confirmationPlan: 'Aankoopregel', confirmationProtocol: 'Protocolregel', confirmationButton: 'Bevestigingsknop', welcomeTitle: 'Titel welkom', welcomeBody: 'Welkomsttekst' };
    Object.assign(labels, { pageTitle: 'Paginatitel in de app', heroLabel: 'Label boven de introductie en het prijsblok', heroTitle: 'Kop introductie', heroBody: 'Body introductie', period: 'Omschrijving naast de prijs bovenaan', pricePeriod: 'Omschrijving naast de prijs onderaan' });
    const lists = { steps: { label: 'Stappen', fields: { title: 'Titel', body: 'Tekst' } }, reviews: { label: 'Reviews', fields: { title: 'Kop', quote: 'Citaat', name: 'Naam' } }, faqs: { label: 'Veelgestelde vragen', fields: { question: 'Vraag', answer: 'Antwoord' } } };
    Object.assign(labels, { homeTitle: 'Kop roze homeblok', homeBody: 'Omschrijving roze homeblok', homeButton: 'Knoptekst roze homeblok' });
    function move(key, index, delta) {
        const rows = [...$form.content[key]];
        [rows[index], rows[index + delta]] = [rows[index + delta], rows[index]];
        $form.content[key] = rows;
    }
    function submit(event) {
        event.preventDefault();
        if (preparingHero) return;
        $form.transform(data => ({ ...data, content: JSON.stringify(data.content) })).post('/admin/plus-page', {
            forceFormData: true, preserveScroll: true,
            onSuccess: () => { $form.hero = null; $form.portrait = null; $form.remove_portrait = false; },
        });
    }
</script>

<AdminLayout title="Protocol kopen">
    <PageHeader title="Protocol kopen" description="Teksten, foto's en reviews van de protocolpagina in de app." />
    <form onsubmit={submit} class="max-w-4xl space-y-8 pb-12">
        <p class="text-sm text-muted-foreground">Deze pagina beheert de presentatie. De protocolprijs geldt voor nieuwe aankopen. Bestaande aankopen en abonnementen blijven ongewijzigd.</p>
        <Field label="Prijs voor nieuwe protocollen (eurocenten)" error={$form.errors.protocol_price_cents}><Input type="number" min="1" step="1" bind:value={$form.protocol_price_cents} /></Field>
        {#if Object.keys($form.errors).length}<div role="alert" class="rounded-lg border border-destructive p-4 text-sm text-destructive">{#each Object.values($form.errors) as error}<p>{error}</p>{/each}</div>{/if}
        <section class="grid gap-6 rounded-xl border bg-card p-5 sm:grid-cols-2">
            <div class="space-y-3"><h2 class="font-semibold">Hero-foto</h2><img src={heroPreview ?? heroImageUrl} alt={heroPreview ? 'Nieuwe hero (nog niet opgeslagen)' : 'Huidige hero'} class="h-[140px] w-full rounded-lg object-cover object-center" /><Field label="Foto vervangen" error={$form.errors.hero}><input class="mt-2 block w-full" type="file" accept="image/jpeg,image/png,image/webp" onchange={selectHero} /></Field><p class="text-sm text-muted-foreground">Grote foto's worden automatisch verkleind. Klik daarna op ‘Wijzigingen opslaan’ om de foto toe te passen.</p>{#if preparingHero}<p role="status" class="text-sm">Foto verkleinen…</p>{:else if $form.hero}<p role="status" class="text-sm">Nieuwe foto geselecteerd, nog niet opgeslagen.</p>{/if}</div>
            <div class="space-y-3"><h2 class="font-semibold">Portret Shelley</h2>{#if portraitImageUrl}<img src={portraitImageUrl} alt="Huidig portret Shelley" class="size-24 rounded-full object-cover" />{:else}<p class="text-sm text-muted-foreground">Nog geen portret aangeleverd. De app toont tot die tijd een initiaal.</p>{/if}<label class="block text-sm">Portret uploaden<input class="mt-2 block w-full" type="file" accept="image/jpeg,image/png,image/webp" onchange={e => $form.portrait = e.currentTarget.files?.[0] ?? null} /></label>{#if portraitImageUrl}<label class="flex gap-2 text-sm"><input type="checkbox" bind:checked={$form.remove_portrait} /> Portret verwijderen</label>{/if}</div>
        </section>
        {#each sections as [title, keys]}
            <section class="space-y-4 border-t pt-5"><h2 class="font-semibold">{title}</h2>{#each keys as key}<Field label={labels[key]} error={$form.errors[`content.${key}`]}>{#if key.endsWith('Body')}<Textarea rows={5} bind:value={$form.content[key]} />{:else}<Input bind:value={$form.content[key]} />{/if}</Field>{/each}</section>
        {/each}
        {#each Object.entries(lists) as [key, list]}
            <section class="space-y-4 border-t pt-5"><h2 class="font-semibold">{list.label}</h2>
                {#if key === 'reviews'}<label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.content.reviewsAreExamples} /> Voorbeeldreviews (toon dit ook in de app)</label>{/if}
                {#each $form.content[key] as row, i}
                    <div class="space-y-3 rounded-lg border p-4">
                        {#each Object.entries(list.fields) as [field, label]}<Field {label} error={$form.errors[`content.${key}.${i}.${field}`]}><Textarea rows={field === 'title' || field === 'name' ? 1 : 3} bind:value={row[field]} /></Field>{/each}
                        <div class="flex flex-wrap gap-2"><Button type="button" variant="outline" disabled={i === 0} onclick={() => move(key, i, -1)}>Omhoog</Button><Button type="button" variant="outline" disabled={i === $form.content[key].length - 1} onclick={() => move(key, i, 1)}>Omlaag</Button><Button type="button" variant="outline" onclick={() => $form.content[key] = $form.content[key].filter((_, j) => j !== i)}>Verwijderen</Button></div>
                    </div>
                {/each}
                <Button type="button" variant="outline" disabled={$form.content[key].length >= 20} onclick={() => $form.content[key] = [...$form.content[key], Object.fromEntries(Object.keys(list.fields).map(field => [field, '']))]}>Toevoegen</Button>
            </section>
        {/each}
        <section class="space-y-3 border-t pt-5"><h2 class="font-semibold">Protocolvoordelen</h2>{#each $form.content.benefits as benefit, i}<div class="flex gap-2"><Input bind:value={$form.content.benefits[i]} /><Button type="button" variant="outline" onclick={() => $form.content.benefits = $form.content.benefits.filter((_, j) => j !== i)}>Verwijderen</Button></div>{/each}<Button type="button" variant="outline" disabled={$form.content.benefits.length >= 20} onclick={() => $form.content.benefits = [...$form.content.benefits, '']}>Voordeel toevoegen</Button></section>
        <div class="sticky bottom-0 border-t bg-background py-4"><Button type="submit" disabled={$form.processing || preparingHero}>{$form.processing ? 'Opslaan…' : 'Wijzigingen opslaan'}</Button></div>
    </form>
</AdminLayout>
