<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import KnowledgeTabs from './KnowledgeTabs.svelte';
    import PolicySummary from './PolicySummary.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Textarea, Select, Badge } from '$lib/components/ui';

    let { profiles, cards, libraryOptions, ingredientOptions, canPublish, mode = 'settings', scoreConflicts = [], filters = {}, errors = {} } = $props();
    let q = $state(filters.q ?? '');
    let status = $state(filters.status ?? '');
    let timer;
    function filterCards() { clearTimeout(timer); timer = setTimeout(() => router.get('/admin/feed-scanner/knowledge', { tab: 'nutrition', q, status }, { preserveState: true, replace: true }), 250); }
    let profile = $state(null);
    let ruleOpen = $state(false);
    let cardOpen = $state(false);
    let card = $state(null);
    let history = $state(null);
    let historyOpen = $state(false);
    const cardForm = useForm({ title: '', explanation: '', internal_notes: '', status: 'draft', library_item_id: '', expected_updated_at: null });
    const rules = useForm({ principles: '', explanation: '', sugar_starch_challenged: '', sugar_starch_healthy: '', sugar_starch_caps: [], organic_selenium_cap: '', unknown_added_selenium_cap: '', exceptions: [], expected_number: null });
    function editProfile(item) {
        profile = item;
        const version = item.versions[0];
        $rules.defaults({ principles: '', explanation: '', ...version?.data, sugar_starch_caps: version?.data?.sugar_starch_caps ?? [], exceptions: version?.data?.exceptions ?? [], expected_number: version?.number ?? null });
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

<svelte:head><title>{mode === 'knowledge' ? 'Kennisbank' : 'Beoordelingsinstellingen'} · EquiApp Admin</title></svelte:head>
<AdminLayout title={`Voerscanner · ${mode === 'knowledge' ? 'Kennisbank' : 'Beoordelingsinstellingen'}`}>
    <PageHeader title={mode === 'knowledge' ? 'Kennisbank' : 'Beoordelingsinstellingen'} description={mode === 'knowledge' ? 'Beheer jouw ingrediëntenkennis en voedingskundige uitleg.' : 'Jouw voedingsprincipes en de grenzen die bij beoordelingen worden gebruikt.'} />
    {#each Object.values(errors) as error}<p role="alert" class="mb-3 text-sm text-destructive">{error}</p>{/each}
    {#if mode === 'settings'}
    <PolicySummary {profiles} />
    {#if scoreConflicts.length}<section class="mt-5 rounded-xl border bg-card p-5 text-sm" aria-label="Regels voor een score van nul"><h2 class="font-semibold">Een voer kan 0/10 scoren</h2><p class="mt-2">De beoordelingsschaal loopt van 0 tot 10. Onderstaande regels kunnen een score van 0/10 opleveren. Per regel zie je of deze actief, concept of historisch is.</p><ul class="mt-3 space-y-2">{#each scoreConflicts as conflict}<li><strong>{conflict.profile}</strong> · versie {conflict.version} · {conflict.state}: {conflict.rule}.</li>{/each}</ul></section>{/if}
    <details class="mt-6 rounded-xl border bg-card p-5"><summary class="cursor-pointer font-semibold">Geavanceerde instellingen</summary>
    <p class="my-4 text-sm text-muted-foreground">Beheer regelconcepten, activering, uitzonderingen en eerdere versies. Nieuwe concepten veranderen de actieve regels pas na activering. De verdere puntenweging is nog niet gekalibreerd.</p>
    <div class="space-y-3">
        {#each profiles as item}
            <Card><CardContent class="space-y-4 p-5">
                <div class="flex items-center justify-between"><h2 class="text-lg font-semibold">{item.name}</h2><Badge variant="muted">{item.active_rule_version_id === item.versions[0]?.id ? 'Actieve plafonds' : 'Conceptbeleid'}</Badge></div>
                <p class="text-sm text-muted-foreground">Nieuwste versie: {item.versions[0]?.number ?? 'nog niet vastgelegd'}.</p>
                {#if item.active_rule_version_id && item.active_rule_version_id !== item.versions[0]?.id}<p class="text-xs text-muted-foreground">Actief: versie {item.versions.find(v => v.id === item.active_rule_version_id)?.number}. Het nieuwe concept verandert bestaande berekeningen nog niet.</p>{/if}
                <div class="flex flex-wrap gap-2"><Button variant="outline" onclick={() => editProfile(item)}>Beleid bewerken</Button><Button variant="ghost" onclick={() => { history = item; historyOpen = true; }}>Historie</Button>{#if canPublish && item.versions[0]?.status === 'draft' && item.versions[0]?.schema_version === 2}<Button onclick={() => router.post(`/admin/feed-scanner/profiles/${item.id}/publish`, { version_id: item.versions[0].id })}>Scoreplafonds activeren</Button>{/if}</div>
            </CardContent></Card>
        {/each}
    </div>
    </details>
    {:else}
    <KnowledgeTabs active="nutrition" />
    <div class="mb-5 flex flex-wrap gap-3"><Input bind:value={q} oninput={filterCards} placeholder="Zoek een kenniskaart…" aria-label="Kenniskaarten zoeken" class="min-w-56 flex-1" /><Select bind:value={status} onchange={filterCards} options={[{ value: '', label: 'Alle statussen' }, { value: 'draft', label: 'Concept' }, { value: 'published', label: 'Gepubliceerd' }, { value: 'archived', label: 'Gearchiveerd' }]} /></div>
    <div class="mb-4 mt-8 flex items-center justify-between gap-3"><h2 class="text-lg font-semibold">Voedingskundige kenniskaarten</h2><Button onclick={() => editCard()}>Kenniskaart toevoegen</Button></div>
    <p class="mb-4 text-sm text-muted-foreground">Deze kaarten bevatten uitleg. Alleen een beschrijving aanpassen verandert geen rekenregel.</p>
    <div class="space-y-3">
        {#each cards as item}
            <Card><CardContent class="flex items-start justify-between gap-4 p-4"><div><h3 class="font-semibold">{item.title}</h3><Badge variant="muted">{item.status === 'published' ? 'Gepubliceerd' : item.status === 'archived' ? 'Gearchiveerd' : 'Concept'}</Badge><p class="mt-2 whitespace-pre-wrap text-sm text-muted-foreground">{item.explanation}</p></div><Button size="sm" variant="outline" onclick={() => editCard(item)}>Bewerken</Button></CardContent></Card>
        {:else}<p class="rounded-lg border p-6 text-sm text-muted-foreground">Voeg bijvoorbeeld een uitlegkaart toe over suiker + zetmeel of mineraalvormen.</p>{/each}
    </div>
    {/if}
    <Modal bind:open={ruleOpen} title={`Voedingsbeleid · ${profile?.name ?? ''}`} size="lg">
        <form id="feed-rules" onsubmit={saveRules} class="space-y-4">
            {#each Object.values($rules.errors) as error}<p class="text-sm text-destructive" role="alert">{error}</p>{/each}
            <Field label="Voedingsprincipes"><Textarea bind:value={$rules.principles} rows={3} /></Field>
            <h3 class="border-t pt-3 font-semibold">Suiker + zetmeel samen · op productbasis</h3>
            <div class="grid gap-4 sm:grid-cols-2"><Field label="Maximum bij gezondheidsuitdagingen (%)"><Input type="number" step="0.1" min="0" max="100" bind:value={$rules.sugar_starch_challenged} /></Field><Field label="Maximum voor gezonde paarden (%)"><Input type="number" step="0.1" min="0" max="100" bind:value={$rules.sugar_starch_healthy} /></Field></div>
            <p class="text-xs text-muted-foreground">Deze twee beleidsgrenzen zijn nog geen puntenaftrek of persoonlijke geschiktheidsbeoordeling.</p>
            <h3 class="border-t pt-3 font-semibold">Scoreplafonds suiker + zetmeel</h3>
            {#each $rules.sugar_starch_caps as tier, index}<div class="grid gap-3 rounded-lg border p-3 sm:grid-cols-3"><Field label="Grens"><Select bind:value={tier.inclusive} options={[{ value: true, label: 'Vanaf (inclusief)' }, { value: false, label: 'Boven (exclusief)' }]} /></Field><Field label="Suiker + zetmeel (%)"><Input type="number" step="any" min="0" max="100" bind:value={tier.threshold} /></Field><Field label="Maximale score /10"><Input type="number" step="0.01" min="0" max="10" bind:value={tier.max_score} /></Field><Button type="button" variant="ghost" size="sm" onclick={() => $rules.sugar_starch_caps = $rules.sugar_starch_caps.filter((_, i) => i !== index)}>Grens verwijderen</Button></div>{/each}
            <Button type="button" variant="outline" onclick={() => $rules.sugar_starch_caps = [...$rules.sugar_starch_caps, { threshold: '', inclusive: false, max_score: '' }]}>Scoregrens toevoegen</Button>
            <p class="text-xs text-muted-foreground">Het strengste toepasselijke plafond geldt. Opslaan maakt een concept; pas na activeren gebruiken nieuwe berekeningen deze regels.</p>
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
        {#if history}{#each history.versions as version}<div class="space-y-2 border-b py-4"><p class="font-semibold">Versie {version.number} · {version.id === history.active_rule_version_id ? 'Actief' : version.status === 'published' ? 'Eerder geactiveerd' : 'Concept'} · {new Date(version.created_at).toLocaleString('nl-NL')}</p><p class="whitespace-pre-wrap text-sm">{version.data.principles}</p><p class="text-sm">Suiker + zetmeel: {version.data.sugar_starch_challenged}% / {version.data.sugar_starch_healthy}%</p>{#each version.data.sugar_starch_caps ?? [] as tier}<p class="text-sm">{tier.inclusive ? 'Vanaf' : 'Boven'} {tier.threshold}%: maximaal {tier.max_score}/10</p>{/each}<p class="text-sm">Seleniumplafonds: {version.data.organic_selenium_cap}/10 · {version.data.unknown_added_selenium_cap}/10</p><p class="whitespace-pre-wrap text-sm">{version.data.explanation}</p>{#each version.data.exceptions ?? [] as row}<p class="text-sm">{ingredientOptions.find(i => i.id === row.ingredient_id)?.name ?? 'Ingrediënt'}: {row.condition} {row.max_percentage != null ? `(maximaal ${row.max_percentage}%)` : ''}</p>{/each}</div>{/each}{/if}
        {#snippet footer()}<Button onclick={() => historyOpen = false}>Sluiten</Button>{/snippet}
    </Modal>
</AdminLayout>
