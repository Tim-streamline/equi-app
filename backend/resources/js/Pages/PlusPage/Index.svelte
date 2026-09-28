<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Field from '$lib/components/Field.svelte';
    import { Button, Input, Textarea } from '$lib/components/ui';
    import { useForm } from '@inertiajs/svelte';
    let { content, heroImageUrl, portraitImageUrl } = $props();
    const form = useForm({ content: structuredClone(content), hero: null, portrait: null, remove_portrait: false });
    const sections = [
        ['Intro en prijs', ['heroTitle', 'heroBody', 'price', 'period', 'heroButton']],
        ['Hoe het werkt', ['stepsTitle', 'stepsButton']],
        ['Shelley', ['guideTitle', 'guideBody', 'guideSignature']],
        ['Reviews en prijsblok', ['reviewsTitle', 'priceButton', 'priceNote']],
        ['Afsluiter en bevestiging', ['closingTitle', 'confirmationTitle', 'confirmationPlan', 'confirmationProtocol', 'confirmationButton', 'welcomeTitle', 'welcomeBody']],
    ];
    const labels = { heroTitle: 'Kop', heroBody: 'Introductie', price: 'Prijs', period: 'Periode', heroButton: 'Eerste knop', stepsTitle: 'Titel stappen', stepsButton: 'Knop na stappen', guideTitle: 'Titel begeleider', guideBody: 'Over Shelley', guideSignature: 'Ondertekening', reviewsTitle: 'Titel reviews', priceButton: 'Knop prijsblok', priceNote: 'Regel onder prijs', closingTitle: 'Afsluitende kop', confirmationTitle: 'Titel bevestiging', confirmationPlan: 'Abonnementregel', confirmationProtocol: 'Protocolregel', confirmationButton: 'Bevestigingsknop', welcomeTitle: 'Titel welkom', welcomeBody: 'Welkomsttekst' };
    const lists = { steps: { label: 'Stappen', fields: { title: 'Titel', body: 'Tekst' } }, reviews: { label: 'Reviews', fields: { title: 'Kop', quote: 'Citaat', name: 'Naam' } }, faqs: { label: 'Veelgestelde vragen', fields: { question: 'Vraag', answer: 'Antwoord' } } };
    function move(key, index, delta) {
        const rows = [...$form.content[key]];
        [rows[index], rows[index + delta]] = [rows[index + delta], rows[index]];
        $form.content[key] = rows;
    }
    function submit(event) {
        event.preventDefault();
        $form.transform(data => ({ ...data, content: JSON.stringify(data.content) })).post('/admin/plus-page', {
            forceFormData: true, preserveScroll: true,
            onSuccess: () => { $form.hero = null; $form.portrait = null; $form.remove_portrait = false; },
        });
    }
</script>

<AdminLayout title="Ontdek Plus">
    <PageHeader title="Ontdek Plus" description="Teksten, foto's en reviews van de Plus-pagina in de app." />
    <form onsubmit={submit} class="max-w-4xl space-y-8 pb-12">
        <p class="text-sm text-muted-foreground">Deze pagina beheert de presentatie. Prijzen en teksten hier wijzigen geen abonnementen, betalingen of intakegegevens.</p>
        {#if Object.keys($form.errors).length}<div role="alert" class="rounded-lg border border-destructive p-4 text-sm text-destructive">{#each Object.values($form.errors) as error}<p>{error}</p>{/each}</div>{/if}
        <section class="grid gap-6 rounded-xl border bg-card p-5 sm:grid-cols-2">
            <div class="space-y-3"><h2 class="font-semibold">Hero-foto</h2><img src={heroImageUrl} alt="Huidige hero" class="h-[140px] w-full rounded-lg object-cover object-center" /><label class="block text-sm">Foto vervangen<input class="mt-2 block w-full" type="file" accept="image/jpeg,image/png,image/webp" onchange={e => $form.hero = e.currentTarget.files?.[0] ?? null} /></label></div>
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
        <section class="space-y-3 border-t pt-5"><h2 class="font-semibold">Plus-voordelen</h2>{#each $form.content.benefits as benefit, i}<div class="flex gap-2"><Input bind:value={$form.content.benefits[i]} /><Button type="button" variant="outline" onclick={() => $form.content.benefits = $form.content.benefits.filter((_, j) => j !== i)}>Verwijderen</Button></div>{/each}<Button type="button" variant="outline" disabled={$form.content.benefits.length >= 20} onclick={() => $form.content.benefits = [...$form.content.benefits, '']}>Voordeel toevoegen</Button></section>
        <div class="sticky bottom-0 border-t bg-background py-4"><Button type="submit" disabled={$form.processing}>{$form.processing ? 'Opslaan…' : 'Wijzigingen opslaan'}</Button></div>
    </form>
</AdminLayout>
