<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Textarea, Select, Badge } from '$lib/components/ui';

    let { profiles, cards, libraryOptions, ingredientOptions, canPublish } = $props();
    let profile = $state(null);
    let ruleOpen = $state(false);
    let cardOpen = $state(false);
    let card = $state(null);
    let history = $state(null);
    let historyOpen = $state(false);
    const cardForm = useForm({ title: '', explanation: '', internal_notes: '', status: 'draft', library_item_id: '', expected_updated_at: null });
    const rules = useForm({ principles: '', explanation: '', sugar_starch_challenged: '', sugar_starch_healthy: '', organic_selenium_cap: '', unknown_added_selenium_cap: '', exceptions: [], expected_number: null });
    function editProfile(item) {
        profile = item;
        const version = item.versions[0];
        $rules.defaults({ principles: '', explanation: '', ...version?.data, exceptions: version?.data?.exceptions ?? [], expected_number: version?.number ?? null });
        $rules.reset(); $rules.clearErrors(); ruleOpen = true;
    }
    function saveRules(e) { e.preventDefault(); $rules.post(`/admin/feed-scanner/profiles/${profile.id}/rules`, { onSuccess: () => ruleOpen = false }); }
    function editCard(item = null) {
        card = item;
        $cardForm.defaults({ title: item?.title ?? '', explanation: item?.explanation ?? '', internal_notes: item?.internal_notes ?? '', status: item?.status ?? 'draft', library_item_id: item?.library_item_id ?? '', expected_updated_at: item?.updated_at ?? null });
        $cardForm.reset(); $cardForm.clearErrors(); cardOpen = true;
    }
    function saveCard(e) { e.preventDefault(); const opts = { onSuccess: () => cardOpen = false }; card ? $cardForm.put(`/admin/feed-scanner/cards/${card.id}`, opts) : $cardForm.post('/admin/feed-scanner/cards', opts); }
</script>

<svelte:head><title>Beoordelingsregels & kennis · EquiApp Admin</title></svelte:head>
<AdminLayout title="Voerscanner · Beoordelingsregels & kennis">
    <PageHeader title="Beoordelingsregels & kennis" description="Beheer jouw voedingsbeleid en de uitleg die daarbij hoort." />
    <div class="mb-5 rounded-lg border bg-muted p-4 text-sm">De grenzen en uitgangspunten zijn alvast vastgelegd. De definitieve puntenweging kalibreren we later met echte producten. Er worden nu nog geen scores berekend.</div>
    <div class="grid gap-4 md:grid-cols-2">
        {#each profiles as item}
            <Card><CardContent class="space-y-4 p-5">
                <div class="flex items-center justify-between"><h2 class="text-lg font-semibold">{item.name}</h2><Badge variant="muted">Conceptbeleid</Badge></div>
                {@const data = item.versions[0]?.data}
                {#if data}<div class="space-y-2 text-sm"><p>Suiker + zetmeel: maximaal {data.sugar_starch_challenged}% bij gezondheidsuitdagingen / {data.sugar_starch_healthy}% voor gezonde paarden.</p><p>Bevestigd organisch selenium: maximaal {data.organic_selenium_cap}/10.</p><p>Bevestigd toegevoegd selenium, vorm onbekend: maximaal {data.unknown_added_selenium_cap}/10.</p><p class="text-muted-foreground">Versie {item.versions[0].number} · Puntenweging nog niet gekalibreerd</p></div>{/if}
                <div class="flex gap-2"><Button variant="outline" onclick={() => editProfile(item)}>Beleid bewerken</Button><Button variant="ghost" onclick={() => { history = item; historyOpen = true; }}>Historie</Button></div>
            </CardContent></Card>
        {/each}
    </div>
    <div class="mb-4 mt-8 flex items-center justify-between gap-3"><h2 class="text-lg font-semibold">Voedingskundige kenniskaarten</h2><Button onclick={() => editCard()}>Kenniskaart toevoegen</Button></div>
    <p class="mb-4 text-sm text-muted-foreground">Deze kaarten bevatten uitleg. Alleen een beschrijving aanpassen verandert geen rekenregel.</p>
    <div class="space-y-3">
        {#each cards as item}
            <Card><CardContent class="flex items-start justify-between gap-4 p-4"><div><h3 class="font-semibold">{item.title}</h3><Badge variant="muted">{item.status === 'published' ? 'Gepubliceerd' : item.status === 'archived' ? 'Gearchiveerd' : 'Concept'}</Badge><p class="mt-2 whitespace-pre-wrap text-sm text-muted-foreground">{item.explanation}</p></div><Button size="sm" variant="outline" onclick={() => editCard(item)}>Bewerken</Button></CardContent></Card>
        {:else}<p class="rounded-lg border p-6 text-sm text-muted-foreground">Voeg bijvoorbeeld een uitlegkaart toe over suiker + zetmeel of mineraalvormen.</p>{/each}
    </div>
    <Modal bind:open={ruleOpen} title={`Voedingsbeleid · ${profile?.name ?? ''}`} size="lg">
        <form id="feed-rules" onsubmit={saveRules} class="space-y-4">
            {#each Object.values($rules.errors) as error}<p class="text-sm text-destructive" role="alert">{error}</p>{/each}
            <Field label="Voedingsprincipes"><Textarea bind:value={$rules.principles} rows={3} /></Field>
            <h3 class="border-t pt-3 font-semibold">Suiker + zetmeel samen · op productbasis</h3>
            <div class="grid gap-4 sm:grid-cols-2"><Field label="Maximum bij gezondheidsuitdagingen (%)"><Input type="number" step="0.1" min="0" max="100" bind:value={$rules.sugar_starch_challenged} /></Field><Field label="Maximum voor gezonde paarden (%)"><Input type="number" step="0.1" min="0" max="100" bind:value={$rules.sugar_starch_healthy} /></Field></div>
            <h3 class="border-t pt-3 font-semibold">Selenium · scoreplafonds</h3>
            <Field label="Bevestigd organisch gebonden selenium (maximaal /10)"><Input type="number" step="0.1" min="0" max="10" bind:value={$rules.organic_selenium_cap} /></Field>
            <Field label="Bevestigd toegevoegd selenium, vorm onbekend (maximaal /10)"><Input type="number" step="0.1" min="0" max="10" bind:value={$rules.unknown_added_selenium_cap} /></Field>
            <p class="text-sm text-muted-foreground">Alleen ‘selenium totaal’ activeert geen plafond. Bevestigd anorganisch selenium geeft geen seleniumgerelateerd plafond. Een handmatige scorecorrectie mag een geldend hard plafond niet overschrijden.</p>
            <h3 class="border-t pt-3 font-semibold">Expliciete ingrediëntenuitzonderingen</h3>
            <p class="text-sm text-muted-foreground">Kies het ingrediënt en beschrijf de voorwaarde. Leg een maximumpercentage vast als de uitzondering van een kleine hoeveelheid afhangt. Dit is voorbereiding voor de latere beoordelingsmotor.</p>
            {#each $rules.exceptions as row, index}<div class="space-y-3 rounded-lg border p-3"><Field label="Ingrediënt"><Select bind:value={row.ingredient_id} options={[{ value: '', label: 'Kies een ingrediënt' }, ...ingredientOptions.map(i => ({ value: i.id, label: i.name }))]} /></Field><Field label="Voorwaarde"><Textarea bind:value={row.condition} rows={2} /></Field><Field label="Maximaal percentage (indien van toepassing)"><Input type="number" min="0" max="100" step="any" bind:value={row.max_percentage} /></Field><Button type="button" size="sm" variant="ghost" onclick={() => $rules.exceptions = $rules.exceptions.filter((_, i) => i !== index)}>Uitzondering verwijderen</Button></div>{/each}
            <Button type="button" variant="outline" onclick={() => $rules.exceptions = [...$rules.exceptions, { ingredient_id: '', condition: '', max_percentage: null }]}>Uitzondering toevoegen</Button>
            <Field label="Toelichting op het beleid"><Textarea bind:value={$rules.explanation} rows={3} /></Field>
            <p class="text-sm text-muted-foreground">Opslaan maakt een nieuwe conceptversie. Eerdere beleidsversies blijven bewaard.</p>
        </form>
        {#snippet footer()}<Button variant="outline" onclick={() => ruleOpen = false}>Annuleren</Button><Button type="submit" form="feed-rules" disabled={$rules.processing}>Concept opslaan</Button>{/snippet}
    </Modal>
    <Modal bind:open={cardOpen} title={card ? 'Kenniskaart bewerken' : 'Kenniskaart toevoegen'} size="lg">
        <form id="feed-card" onsubmit={saveCard} class="space-y-4">
            {#each Object.values($cardForm.errors) as error}<p class="text-sm text-destructive" role="alert">{error}</p>{/each}
            <Field label="Titel"><Input bind:value={$cardForm.title} /></Field>
            <Field label="Uitleg voor de gebruiker"><Textarea bind:value={$cardForm.explanation} rows={5} /></Field>
            <Field label="Bibliotheekitem"><Select bind:value={$cardForm.library_item_id} options={[{ value: '', label: 'Geen koppeling' }, ...libraryOptions.map(i => ({ value: i.id, label: i.title }))]} /></Field>
            <Field label="Interne notities"><Textarea bind:value={$cardForm.internal_notes} rows={2} /></Field>
            <Field label="Status"><Select bind:value={$cardForm.status} disabled={!canPublish} options={[{ value: 'draft', label: 'Concept' }, { value: 'published', label: 'Gepubliceerd' }, { value: 'archived', label: 'Gearchiveerd' }]} /></Field>
        </form>
        {#snippet footer()}<Button variant="outline" onclick={() => cardOpen = false}>Annuleren</Button><Button form="feed-card" type="submit" disabled={$cardForm.processing}>Opslaan</Button>{/snippet}
    </Modal>
    <Modal bind:open={historyOpen} title={`Beleidshistorie · ${history?.name ?? ''}`} size="lg">
        {#if history}{#each history.versions as version}<div class="space-y-2 border-b py-4"><p class="font-semibold">Versie {version.number} · Concept · {new Date(version.created_at).toLocaleString('nl-NL')}</p><p class="whitespace-pre-wrap text-sm">{version.data.principles}</p><p class="text-sm">Suiker + zetmeel: {version.data.sugar_starch_challenged}% / {version.data.sugar_starch_healthy}%</p><p class="text-sm">Seleniumplafonds: {version.data.organic_selenium_cap}/10 · {version.data.unknown_added_selenium_cap}/10</p><p class="whitespace-pre-wrap text-sm">{version.data.explanation}</p>{#each version.data.exceptions ?? [] as row}<p class="text-sm">{ingredientOptions.find(i => i.id === row.ingredient_id)?.name ?? 'Ingrediënt'}: {row.condition} {row.max_percentage != null ? `(maximaal ${row.max_percentage}%)` : ''}</p>{/each}</div>{/each}{/if}
        {#snippet footer()}<Button onclick={() => historyOpen = false}>Sluiten</Button>{/snippet}
    </Modal>
</AdminLayout>
