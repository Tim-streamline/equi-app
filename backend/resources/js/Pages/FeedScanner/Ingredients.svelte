<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Textarea, Select, Badge } from '$lib/components/ui';

    let { ingredients, filters, libraryOptions, canPublish, errors = {} } = $props();
    let q = $state(filters.q ?? '');
    let status = $state(filters.status ?? '');
    let open = $state(false);
    let editing = $state(null);
    let history = $state(null);
    let historyOpen = $state(false);
    let timer;
    const states = { draft: 'Concept', published: 'Gepubliceerd', archived: 'Gearchiveerd', legacy: 'Bestaand · nog te controleren' };
    const assessments = [{ value: 'unrated', label: 'Nog niet beoordeeld' }, { value: 'preferred', label: 'Voorkeur' }, { value: 'conditional', label: 'Voorwaardelijk acceptabel' }, { value: 'critical', label: 'Kritisch beoordelen / blacklist' }];
    const empty = () => ({ name: '', category: '', assessment: 'unrated', explanation: '', exceptions: '', default_tag: '', library_item_id: '', internal_notes: '', terms: [], expected_number: null });
    const form = useForm(empty());
    function apply() { clearTimeout(timer); timer = setTimeout(() => router.get('/admin/feed-scanner/ingredients', { q, status }, { preserveState: true, replace: true }), 250); }
    function edit(record = null) {
        editing = record;
        const version = record?.versions?.[0];
        $form.defaults({ ...empty(), ...(version?.data ?? {}), name: version?.data?.name ?? record?.name ?? '', internal_notes: record?.internal_notes ?? '', expected_number: version?.number ?? null });
        $form.reset(); $form.clearErrors(); open = true;
    }
    function save(e) {
        e.preventDefault();
        const options = { onSuccess: () => { open = false; } };
        editing ? $form.put(`/admin/feed-scanner/ingredients/${editing.id}`, options) : $form.post('/admin/feed-scanner/ingredients', options);
    }
    function publish(record) {
        router.post(`/admin/feed-scanner/ingredients/${record.id}/publish`, { version_id: record.versions[0].id });
    }
    function archive(record) { if (confirm('Dit ingrediënt archiveren? De historie blijft bewaard.')) router.post(`/admin/feed-scanner/ingredients/${record.id}/archive`); }
</script>

<svelte:head><title>Ingrediënten · EquiApp Admin</title></svelte:head>
<AdminLayout title="Voerscanner · Ingrediënten">
    <PageHeader title="Ingrediëntenkennis" description="Leg jouw uitleg en beoordeling per ingrediënt vast.">
        {#snippet actions()}<Button onclick={() => edit()}>Ingrediënt toevoegen</Button>{/snippet}
    </PageHeader>
    {#each Object.values(errors) as error}<p role="alert" class="mb-3 rounded-lg border border-destructive p-3 text-sm text-destructive">{error}</p>{/each}
    <Card><CardContent class="space-y-4 p-4">
        <div class="flex flex-wrap gap-3">
            <Input class="min-w-56 flex-1" placeholder="Zoek een ingrediënt of benaming…" bind:value={q} oninput={apply} aria-label="Ingrediënten zoeken" />
            <Select bind:value={status} onchange={apply} options={[{ value: '', label: 'Alle statussen' }, ...Object.entries(states).map(([value, label]) => ({ value, label }))]} />
        </div>
        {#each ingredients.data as item (item.id)}
            {@const latest = item.versions?.[0]}
            <div class="flex flex-wrap items-center justify-between gap-3 border-t py-4">
                <div class="min-w-0 flex-1">
                    <div class="font-semibold">{latest?.data?.name ?? item.name}</div>
                    <div class="mt-1 flex flex-wrap gap-2 text-sm text-muted-foreground"><Badge variant="muted">{states[item.catalog_status]}</Badge><span>Versie {latest?.number ?? 1}</span>{#if item.needs_review}<span>· Concept te controleren</span>{/if}</div>
                    <p class="mt-2 line-clamp-2 text-sm text-muted-foreground">{latest?.data?.explanation ?? item.description ?? ''}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onclick={() => edit(item)}>Bewerken</Button>
                    <Button size="sm" variant="ghost" onclick={() => { history = item; historyOpen = true; }}>Historie</Button>
                    {#if canPublish && latest?.status === 'draft' && item.catalog_status !== 'archived'}<Button size="sm" onclick={() => publish(item)}>Publiceren</Button>{/if}
                    {#if canPublish && item.catalog_status !== 'archived'}<Button size="sm" variant="ghost" onclick={() => archive(item)}>Archiveren</Button>{/if}
                </div>
            </div>
        {:else}<p class="py-8 text-center text-muted-foreground">Geen ingrediënten gevonden.</p>{/each}
        <Pagination paginator={ingredients} />
    </CardContent></Card>
    <Modal bind:open title={editing ? 'Ingrediënt bewerken' : 'Ingrediënt toevoegen'} size="lg">
        <form id="feed-ingredient" onsubmit={save} class="space-y-4">
            {#each Object.entries($form.errors) as [key, error]}<p class="text-sm text-destructive" role="alert">{error}</p>{/each}
            <Field label="Naam"><Input bind:value={$form.name} required /></Field>
            <Field label="Categorie"><Input bind:value={$form.category} placeholder="Bijvoorbeeld: suikerhoudende grondstof" /></Field>
            <Field label="Jouw basisbeoordeling"><Select bind:value={$form.assessment} options={assessments} /></Field>
            <Field label="Uitleg voor de gebruiker"><Textarea bind:value={$form.explanation} rows={3} /></Field>
            <Field label="Uitzonderingen"><Textarea bind:value={$form.exceptions} rows={2} placeholder="Beschrijf wanneer dit ingrediënt wel acceptabel kan zijn." /></Field>
            <p class="text-xs text-muted-foreground">Een uitzondering die de berekening moet beïnvloeden leg je ook vast bij Beoordelingsregels & kennis.</p>
            <section class="space-y-3 border-t pt-4">
                <div class="flex items-center justify-between"><h3 class="font-medium">Alternatieve benamingen</h3><Button type="button" size="sm" variant="outline" onclick={() => $form.terms = [...$form.terms, { term: '', language: 'nl', kind: 'exact' }]}>Benaming toevoegen</Button></div>
                <p class="text-sm text-muted-foreground">Een synoniem betekent hetzelfde. Een verwante term geeft alleen een suggestie.</p>
                {#each $form.terms as term, index}
                    <div class="grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_5rem_10rem_auto]">
                        <Input bind:value={term.term} placeholder="Benaming" aria-label={`Benaming ${index + 1}`} />
                        <Input bind:value={term.language} placeholder="nl" aria-label="Taalcode" />
                        <Select bind:value={term.kind} options={[{ value: 'exact', label: 'Exact synoniem' }, { value: 'related', label: 'Verwante term' }]} />
                        <Button type="button" variant="ghost" onclick={() => $form.terms = $form.terms.filter((_, i) => i !== index)}>Verwijderen</Button>
                    </div>
                {/each}
            </section>
            <Field label="Gerelateerd bibliotheekitem"><Select bind:value={$form.library_item_id} options={[{ value: '', label: 'Geen koppeling' }, ...libraryOptions.map(i => ({ value: i.id, label: i.title }))]} /></Field>
            <Field label="Interne notities"><Textarea bind:value={$form.internal_notes} rows={2} /></Field>
            <p class="text-sm text-muted-foreground">Je slaat een nieuw concept op. Eerdere versies blijven bewaard.</p>
        </form>
        {#snippet footer()}<Button variant="outline" onclick={() => open = false}>Annuleren</Button><Button form="feed-ingredient" type="submit" disabled={$form.processing}>Concept opslaan</Button>{/snippet}
    </Modal>
    <Modal bind:open={historyOpen} title="Ingrediënthistorie" size="lg">
        {#if history}{#each history.versions as version}<div class="space-y-2 border-b py-4"><p class="font-medium">Versie {version.number} · {states[version.status] ?? version.status}</p><p class="text-sm text-muted-foreground">{new Date(version.created_at).toLocaleString('nl-NL')}</p><p>{version.data.name}</p><p class="whitespace-pre-wrap text-sm">{version.data.explanation}</p><p class="whitespace-pre-wrap text-sm">{version.data.exceptions}</p>{#each version.data.terms ?? [] as term}<p class="text-sm">{term.term} · {term.language} · {term.kind === 'exact' ? 'Exact synoniem' : 'Verwante term'}</p>{/each}</div>{/each}{/if}
        {#snippet footer()}<Button onclick={() => historyOpen = false}>Sluiten</Button>{/snippet}
    </Modal>
</AdminLayout>
