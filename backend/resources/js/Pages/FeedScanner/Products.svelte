<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import AssessmentHistory from './AssessmentHistory.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { csrfHeaders } from '$lib/csrf.js';
    import { Card, CardContent, Button, Input, Textarea, Select, Badge } from '$lib/components/ui';

    let { products, filters, reviewCount, ingredientOptions, categories, nutrientOptions, canPublish, assessmentProfiles = [], errors = {} } = $props();
    let q = $state(filters.q ?? '');
    let status = $state(filters.status ?? '');
    let category = $state(filters.category ?? '');
    let sort = $state(filters.sort ?? 'name');
    let editing = $state(null);
    let open = $state(false);
    let tab = $state('general');
    let detailId = $state(filters.open ?? null);
    const detail = $derived(products.data.find(p => p.id === detailId));
    let historyOpen = $state(!!filters.open);
    let timer;
    let uploadVersion = $state(null);
    let uploadOpen = $state(false);
    let aiPhotos = $state(null);
    let aiText = $state('');
    let aiBusy = $state(false);
    let aiError = $state('');
    let aiNotes = $state(null);
    const labels = { draft: 'Concept', approved: 'Goedgekeurd', needs_info: 'Informatie ontbreekt', rejected: 'Afgewezen', legacy: 'Bestaand · nog te controleren' };
    const purposes = { packaging: 'Verpakking', composition: 'Samenstelling', analysis: 'Analyse' };
    const bases = { product: 'Productbasis', dry_matter: 'Droge-stofbasis', unknown: 'Onbekend' };
    const qualifiers = { exact: 'Exact vermeld', less_than: 'Minder dan (<)', greater_than: 'Meer dan (>)', minimum: 'Minimaal (≥)', maximum: 'Maximaal (≤)' };
    const empty = () => ({ brand: '', name: '', barcode: '', category: '', composition_text: '', recognition_enabled: false, usage: '', source: '', internal_notes: '', ingredients: [], nutrients: [], related_product_id: null, source_version_id: null, expected_number: null, approve: false });
    const form = useForm(empty());
    const uploadForm = useForm({ file: null, purpose: 'composition' });
    function apply() { clearTimeout(timer); timer = setTimeout(() => router.get('/admin/feed-scanner/products', { q, status, sort, category }, { preserveState: true, replace: true }), 250); }
    function edit(record = null, version = record?.versions?.find(v => v.is_current)) {
        editing = record; tab = 'general';
        const data = version?.data ?? { brand: record?.brand ?? '', name: record?.name ?? '' };
        $form.defaults({ ...empty(), ...data, category: Object.hasOwn(categories, data.category) ? data.category : '', barcode: data.barcode ?? '',
            ingredients: (data.ingredients ?? []).map(row => ({ ingredient_id: '', label: ingredientOptions.find(i => i.id === row.ingredient_id)?.name ?? '', amount: '', match_status: row.ingredient_id ? 'confirmed' : 'unknown', added_selenium: 'unknown', selenium_form: '', evidence: '', ...row })),
            nutrients: (data.nutrients ?? []).map(row => ({ qualifier: 'exact', ...row })),
            internal_notes: record?.internal_notes ?? '', source_version_id: version?.id ?? null, expected_number: version?.number ?? null, approve: false });
        $form.reset(); $form.clearErrors(); historyOpen = false; open = true;
        aiPhotos = null; aiText = ''; aiError = ''; aiNotes = null;
    }
    async function prefill() {
        aiError = ''; aiNotes = null;
        const body = new FormData();
        for (const file of aiPhotos ?? []) body.append('photos[]', file);
        if (aiText.trim()) body.append('text', aiText.trim());
        if (!body.has('text') && !body.has('photos[]')) { aiError = 'Voeg een foto toe of plak de tekst van het etiket.'; return; }
        aiBusy = true;
        try {
            const response = await fetch('/admin/feed-scanner/products/prefill', { method: 'POST', body, headers: { Accept: 'application/json', ...csrfHeaders() } });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) { aiError = result.message ?? 'Uitlezen is niet gelukt. Probeer het opnieuw.'; return; }
            const { ingredients, nutrients, ...fields } = result.data;
            // Assign per key so the form store notices each change; Object.assign would bypass it.
            for (const [key, value] of Object.entries(fields)) $form[key] = value;
            if (ingredients.length) $form.ingredients = ingredients;
            if (nutrients.length) $form.nutrients = nutrients;
            if (!$form.source) $form.source = aiPhotos?.length ? 'Etiket, uitgelezen met AI' : 'Geplakte fabrikanttekst, uitgelezen met AI';
            aiNotes = result.notes;
        } catch {
            aiError = 'Geen verbinding. Probeer het opnieuw.';
        } finally {
            aiBusy = false;
        }
    }
    function save(e) {
        e.preventDefault();
        const options = { onSuccess: () => open = false };
        editing ? $form.put(`/admin/feed-scanner/products/${editing.id}`, options) : $form.post('/admin/feed-scanner/products', options);
    }
    function reviewProduct(record, decision, version = record.versions.find(v => v.is_current)) {
        const notes = decision === 'approve' ? '' : prompt('Welke informatie ontbreekt of waarom wijs je dit af?');
        if (notes === null) return;
        router.post(`/admin/feed-scanner/products/${record.id}/review`, { version_id: version.id, decision, notes });
    }
    function remove(record) {
        if (confirm(`${record.brand} · ${record.name} definitief verwijderen? Dit kan niet ongedaan worden gemaakt.`)) router.delete(`/admin/feed-scanner/products/${record.id}`, { preserveScroll: true });
    }
    function archive(record) { if (confirm('Dit product archiveren? De historie blijft bewaard.')) router.post(`/admin/feed-scanner/products/${record.id}/archive`); }
    function move(index, step) { const rows = [...$form.ingredients]; [rows[index], rows[index + step]] = [rows[index + step], rows[index]]; $form.ingredients = rows; }
    function beginUpload(version) { uploadVersion = version; $uploadForm.reset(); $uploadForm.clearErrors(); historyOpen = false; uploadOpen = true; }
    function upload(e) { e.preventDefault(); $uploadForm.post(`/admin/feed-scanner/versions/${uploadVersion.id}/attachments`, { forceFormData: true, onSuccess: () => uploadOpen = false }); }
</script>

<svelte:head><title>Voerproducten · EquiApp Admin</title></svelte:head>
<AdminLayout title="Voerscanner · Voerproducten">
    <PageHeader title="Voerproducten" description={`${products.total} producten in dit overzicht · ${reviewCount} wachten op controle`}>
        {#snippet actions()}<Button onclick={() => edit()}>Product toevoegen</Button>{/snippet}
    </PageHeader>
    <div class="mb-5 flex flex-wrap gap-2" aria-label="Productstatus">
        {#each [['', 'Alle producten'], ['review', 'Te beoordelen'], ['approved', 'Goedgekeurd'], ['needs_info', 'Informatie ontbreekt']] as [value, label]}<Button variant={status === value ? 'default' : 'outline'} aria-pressed={status === value} onclick={() => { status = value; apply(); }}>{label}</Button>{/each}
    </div>
    {#each Object.values(errors) as error}<p role="alert" class="mb-3 rounded-lg border border-destructive p-3 text-sm text-destructive">{error}</p>{/each}
    <Card><CardContent class="space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <Input class="min-w-56 flex-1" placeholder="Zoek merk, product of barcode…" bind:value={q} oninput={apply} aria-label="Producten zoeken" />
            <Select bind:value={category} onchange={apply} options={[{ value: '', label: 'Alle categorieën' }, ...Object.entries(categories).map(([value, label]) => ({ value, label }))]} />
            <Select bind:value={sort} onchange={apply} options={[{ value: 'name', label: 'Merk en naam' }, { value: 'newest', label: 'Nieuwste eerst' }, { value: 'scans', label: 'Meest gescand' }]} />
        </div>
        {#each products.data as product (product.id)}
            {@const latest = product.versions?.find(v => v.is_current)}
            {@const pendingSubmissions = product.submissions ?? []}
            <div class="space-y-3 border-t py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{latest?.data?.brand ?? product.brand} · {latest?.data?.name ?? product.name}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <Badge variant="muted">{product.catalog_status === 'archived' ? 'Gearchiveerd' : labels[latest?.status] ?? 'Nog te controleren'}</Badge>
                            <span>{categories[latest?.data?.category] ?? latest?.data?.category ?? 'Categorie nog kiezen'}</span><span>· {product.scans_count} scans</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">Toegevoegd: {new Date(product.created_at).toLocaleDateString('nl-NL')} · Laatst gecontroleerd: {product.versions.find(v => v.reviewed_at) ? new Date(product.versions.find(v => v.reviewed_at).reviewed_at).toLocaleDateString('nl-NL') : 'nog niet'}</p>
                        {#if product.published_version_id && latest?.id !== product.published_version_id}<p class="mt-1 text-xs text-muted-foreground">De eerder goedgekeurde versie blijft beschikbaar tot het nieuwe concept is goedgekeurd.</p>{/if}
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" onclick={() => edit(product)}>Bewerken</Button>
                        {#if pendingSubmissions.length}<Button size="sm" variant="outline" onclick={() => { detailId = product.id; historyOpen = true; }}>Ingezonden etiketten ({pendingSubmissions.length})</Button>{/if}
                        {#if ['draft', 'needs_info', 'legacy'].includes(latest?.status)}<Button size="sm" variant="outline" onclick={() => { detailId = product.id; historyOpen = true; }}>Product bekijken</Button>{/if}
                        <Button size="sm" variant="ghost" onclick={() => { detailId = product.id; historyOpen = true; }}>Beoordelingen & historie</Button>
                        {#if ['draft', 'needs_info'].includes(latest?.status) && product.catalog_status !== 'archived'}
                            <Button size="sm" variant="outline" onclick={() => beginUpload(latest)}>Etiket toevoegen</Button>
                            {#if canPublish}<Button size="sm" onclick={() => reviewProduct(product, 'approve')}>Goedkeuren</Button><Button size="sm" variant="ghost" onclick={() => reviewProduct(product, 'needs_info')}>Meer informatie nodig</Button><Button size="sm" variant="ghost" onclick={() => reviewProduct(product, 'reject')}>Afwijzen</Button>{/if}
                        {/if}
                        {#if canPublish && product.catalog_status !== 'archived'}<Button size="sm" variant="ghost" onclick={() => archive(product)}>Archiveren</Button>{/if}
                        {#if canPublish && !product.delete_blockers?.length}<Button size="sm" variant="ghost" class="text-destructive" onclick={() => remove(product)}>Verwijderen</Button>{/if}
                    </div>
                </div>
                {#if latest?.review_notes}<p class="text-sm text-muted-foreground">Controle: {latest.review_notes}</p>{/if}
            </div>
        {:else}<p class="py-8 text-center text-muted-foreground">Geen producten gevonden.</p>{/each}
        <Pagination paginator={products} />
    </CardContent></Card>
    <Modal bind:open title={editing ? 'Product bewerken' : 'Product toevoegen'} size="xl">
        {#if $form.source_version_id}<p class="mb-4 text-sm text-muted-foreground">Wijzigingen worden opgeslagen als een nieuwe versie. De vorige samenstelling en beoordelingen blijven bewaard in de historie.</p>{/if}
        <details class="mb-4 rounded-lg border p-3" open={!editing}>
            <summary class="cursor-pointer font-medium">Invullen met AI</summary>
            <div class="mt-3 space-y-3">
                <p class="text-sm text-muted-foreground">Upload foto's van het etiket of plak de tekst van de fabrikantwebsite. De AI vult Algemeen, Samenstelling en Analyse in. Er wordt nog niets opgeslagen: controleer alles en sla daarna zelf op.</p>
                <Field label="Etiketfoto's (maximaal 4)"><input type="file" accept="image/jpeg,image/png,image/webp" multiple bind:files={aiPhotos} class="block w-full text-sm" /></Field>
                <Field label="Of plak de tekst"><Textarea bind:value={aiText} rows={4} placeholder="Samenstelling, analytische bestanddelen, toevoegingen…" /></Field>
                <Button type="button" variant="outline" disabled={aiBusy} onclick={prefill}>{aiBusy ? 'Bezig met uitlezen…' : 'Invullen met AI'}</Button>
                {#if aiError}<p role="alert" class="text-sm text-destructive">{aiError}</p>{/if}
                {#if aiNotes}
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                        <p class="font-medium">Ingevuld. Controleer dit:</p>
                        {#if aiNotes.length}<ul class="mt-1 list-disc pl-5">{#each aiNotes as note}<li>{note}</li>{/each}</ul>{:else}<p>Geen twijfelpunten gevonden. Loop de tabbladen toch even na.</p>{/if}
                    </div>
                {/if}
            </div>
        </details>
        <div class="mb-4 flex flex-wrap gap-2">{#each [['general', 'Algemeen'], ['ingredients', 'Samenstelling'], ['analysis', 'Analyse']] as [value, label]}<Button size="sm" variant={tab === value ? 'default' : 'outline'} onclick={() => tab = value}>{label}</Button>{/each}</div>
        <form id="feed-product" onsubmit={save} class="space-y-4">
            {#each Object.entries($form.errors) as [key, error]}<p role="alert" class="text-sm text-destructive">{error}</p>{/each}
            {#if tab === 'general'}
                <div class="grid gap-4 sm:grid-cols-2"><Field label="Merk"><Input bind:value={$form.brand} /></Field><Field label="Productnaam"><Input bind:value={$form.name} /></Field></div>
                <Field label="Categorie"><Select bind:value={$form.category} options={[{ value: '', label: 'Kies een categorie' }, ...Object.entries(categories).map(([value, label]) => ({ value, label }))]} /></Field>
                <p class="text-xs text-muted-foreground">Beoordelingsprofiel: {$form.category === 'balancer' ? 'Balancer' : 'Standaardvoer'}. De puntenweging wordt later gekalibreerd.</p>
                <Field label="Barcode (optioneel)"><Input bind:value={$form.barcode} /></Field>
                <Field label="Gebruik en dosering volgens het etiket"><Textarea bind:value={$form.usage} rows={2} /></Field>
                <Field label="Bron"><Input bind:value={$form.source} placeholder="Bijvoorbeeld fabrikantwebsite of etiket" /></Field>
                <Field label="Interne notities"><Textarea bind:value={$form.internal_notes} rows={2} /></Field>
            {:else if tab === 'ingredients'}
                <Field label="Oorspronkelijke samenstelling op het etiket"><Textarea bind:value={$form.composition_text} rows={3} /></Field>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" bind:checked={$form.recognition_enabled} class="mt-1" /><span>Herkennen op samenstelling inschakelen<span class="block text-muted-foreground">Controleer eerst de volledige ingrediënten- en toevoegingenlijst, inclusief volgorde en percentages. Herkenning gebruikt deze tekst pas nadat de productversie is goedgekeurd.</span></span></label>
                <div class="flex items-center justify-between"><h3 class="font-medium">Ingrediënten in etiketvolgorde</h3><Button type="button" size="sm" variant="outline" onclick={() => $form.ingredients = [...$form.ingredients, { ingredient_id: '', label: '', amount: '', match_status: 'unknown', added_selenium: 'unknown', selenium_form: '', evidence: '' }]}>Ingrediënt toevoegen</Button></div>
                {#each $form.ingredients as row, index}
                    <div class="space-y-3 rounded-lg border p-3">
                        <div class="flex items-center justify-between"><span class="text-sm font-medium">Ingrediënt {index + 1}</span><div class="flex gap-1"><Button type="button" size="sm" variant="ghost" disabled={index === 0} onclick={() => move(index, -1)} aria-label="Ingrediënt omhoog">↑</Button><Button type="button" size="sm" variant="ghost" disabled={index === $form.ingredients.length - 1} onclick={() => move(index, 1)} aria-label="Ingrediënt omlaag">↓</Button><Button type="button" size="sm" variant="ghost" onclick={() => $form.ingredients = $form.ingredients.filter((_, i) => i !== index)}>Verwijderen</Button></div></div>
                        <div class="grid gap-3 sm:grid-cols-2"><Field label="Naam op het etiket"><Input bind:value={row.label} /></Field><Field label="Hoeveelheid indien vermeld"><Input bind:value={row.amount} placeholder="Bijvoorbeeld 12%" /></Field></div>
                        <Field label="Koppeling aan kenniskaart"><Select bind:value={row.ingredient_id} options={[{ value: '', label: 'Nog niet gekoppeld' }, ...ingredientOptions.map(i => ({ value: i.id, label: i.name }))]} /></Field>
                        <Field label="Zekerheid van de koppeling"><Select bind:value={row.match_status} options={[{ value: 'unknown', label: 'Nog onbekend' }, { value: 'suggested', label: 'Voorstel · nog controleren' }, { value: 'confirmed', label: 'Bevestigd' }]} /></Field>
                        <details><summary class="cursor-pointer text-sm text-muted-foreground">Seleniumtoevoeging vastleggen (indien relevant)</summary><div class="mt-3 space-y-3">
                            <Field label="Is toegevoegd selenium bevestigd?"><Select bind:value={row.added_selenium} options={[{ value: 'unknown', label: 'Niet vastgesteld / niet relevant' }, { value: 'confirmed', label: 'Ja, bevestigd op basis van de bron' }, { value: 'not_added', label: 'Bevestigd niet toegevoegd' }]} /></Field>
                            <Field label="Chemische vorm"><Select bind:value={row.selenium_form} options={[{ value: '', label: 'Niet vastgelegd' }, { value: 'unknown', label: 'Vorm onbekend' }, { value: 'organic', label: 'Organisch gebonden' }, { value: 'inorganic', label: 'Anorganisch' }]} /></Field>
                            <Field label="Waarop is deze bevestiging gebaseerd?"><Input bind:value={row.evidence} placeholder="Vermeld de exacte etiketregel of fabrikantbron." /></Field>
                        </div></details>
                    </div>
                {/each}
            {:else}
                <p class="text-sm text-muted-foreground">Neem waarden en analysebasis over uit de bron. Kies ‘Onbekend’ als de basis niet vermeld is. Laat ontbrekende waarden weg.</p>
                {#each $form.nutrients as row, index}
                    <div class="space-y-3 rounded-lg border p-3">
                        <div class="grid gap-3 sm:grid-cols-2"><Field label="Voedingswaarde"><Select bind:value={row.code} options={Object.entries(nutrientOptions).map(([value, label]) => ({ value, label }))} /></Field><Field label="Waarde"><Input type="number" min="0" step="any" bind:value={row.value} /></Field></div>
                        <Field label="Hoe is de waarde vermeld?"><Select bind:value={row.qualifier} options={Object.entries(qualifiers).map(([value, label]) => ({ value, label }))} /></Field>
                        <div class="grid gap-3 sm:grid-cols-2"><Field label="Eenheid"><Select bind:value={row.unit} options={['%', 'g/kg', 'mg/kg'].map(value => ({ value, label: value }))} /></Field><Field label="Analysebasis"><Select bind:value={row.basis} options={Object.entries(bases).map(([value, label]) => ({ value, label }))} /></Field></div>
                        <Field label="Bron / oorspronkelijke vermelding"><Input bind:value={row.source} /></Field>
                        <Button type="button" size="sm" variant="ghost" onclick={() => $form.nutrients = $form.nutrients.filter((_, i) => i !== index)}>Waarde verwijderen</Button>
                    </div>
                {/each}
                <Button type="button" variant="outline" onclick={() => $form.nutrients = [...$form.nutrients, { code: 'sugar', value: '', qualifier: 'exact', unit: '%', basis: 'unknown', source: '' }]}>Voedingswaarde toevoegen</Button>
            {/if}
            <p class="text-sm text-muted-foreground">Elke wijziging wordt een nieuw concept. Etiketten voeg je na het opslaan toe vanuit het overzicht.</p>
        </form>
        {#snippet footer()}<Button variant="outline" onclick={() => open = false}>Annuleren</Button><Button form="feed-product" type="submit" disabled={$form.processing} onclick={() => $form.approve = false}>Concept opslaan</Button>{#if canPublish}<Button form="feed-product" type="submit" disabled={$form.processing} onclick={() => $form.approve = true}>Opslaan en goedkeuren</Button>{/if}{/snippet}
    </Modal>
    <Modal bind:open={uploadOpen} title="Etiket toevoegen">
        <form id="feed-upload" onsubmit={upload} class="space-y-4">
            {#each Object.values($uploadForm.errors) as error}<p role="alert" class="text-destructive">{error}</p>{/each}
            <Field label="Wat staat er op dit bestand?"><Select bind:value={$uploadForm.purpose} options={Object.entries(purposes).map(([value, label]) => ({ value, label }))} /></Field>
            <Field label="Foto of PDF (maximaal 15 MB)"><input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onchange={e => $uploadForm.file = e.target.files?.[0] ?? null} /></Field>
            <p class="text-sm text-muted-foreground">Het bestand is alleen beschikbaar voor bevoegde beheerders. Er wordt nog geen AI-herkenning uitgevoerd.</p>
        </form>
        {#snippet footer()}<Button variant="outline" onclick={() => uploadOpen = false}>Annuleren</Button><Button form="feed-upload" type="submit" disabled={$uploadForm.processing || !$uploadForm.file}>Etiket toevoegen</Button>{/snippet}
    </Modal>
    <Modal bind:open={historyOpen} title="Productdetails & historie" size="xl">
        {#if detail?.related_product}
            <p class="mb-4 text-sm">Apart recept, gerelateerd aan <a class="text-primary underline" href={`/admin/feed-scanner/products?open=${detail.related_product.id}`}>{detail.related_product.brand} · {detail.related_product.name}</a>.</p>
        {/if}
        {#each detail?.submissions ?? [] as submission}
            <div class="mb-4 rounded-xl border p-4 space-y-2">
                <h3 class="font-semibold">Ingezonden etiket · {new Date(submission.created_at).toLocaleDateString('nl-NL')}</h3>
                <p class="text-sm">{({draft: 'Foto’s ontvangen / nog aan te vullen', needs_photos: 'Wacht op analyse en voorkant', processing: 'Etiket wordt uitgelezen', failed: 'Automatisch uitlezen niet gelukt', completed: 'Beoordeling beschikbaar', review_required: 'Handmatige beoordeling nodig'})[submission.status] ?? submission.status}</p>
                <p class="text-xs text-muted-foreground">Deze foto’s zijn bewijsmateriaal bij het product. Corrigeer en beoordeel het product via de productacties.</p>
                {#each submission.attachments ?? [] as file}<a href={`/admin/feed-scanner/attachments/${file.id}`} class="block text-sm text-primary underline">{purposes[file.purpose]}: {file.original_name}</a>{/each}
                {#if submission.message}<p class="text-sm text-muted-foreground">{submission.message}</p>{/if}
                {#if ['draft', 'failed', 'needs_photos'].includes(submission.status)}<Button size="sm" variant="outline" onclick={() => router.post(`/admin/feed-scanner/submissions/${submission.id}/retry`)}>Etiket opnieuw uitlezen</Button>{/if}
                {#if submission.recognition}<details><summary class="cursor-pointer text-sm">Controle van de samenstelling bekijken</summary><pre class="mt-2 whitespace-pre-wrap break-words text-xs">{JSON.stringify(submission.recognition, null, 2)}</pre></details>{/if}
                {#if submission.extraction}<details><summary class="cursor-pointer text-sm">Uitgelezen brongegevens controleren</summary><pre class="mt-2 whitespace-pre-wrap break-words text-xs">{JSON.stringify(submission.extraction, null, 2)}</pre></details>{/if}
            </div>
        {/each}
        {#if detail}<AssessmentHistory product={detail} {canPublish} profiles={assessmentProfiles} {errors} />{/if}
        <h3 class="mt-8 text-lg font-semibold">Productgegevens & versiehistorie</h3>
        {#if detail}{#each detail.versions as version}
            <section class="space-y-3 border-t py-5">
                <div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold">{version.number === 1 ? 'Oorspronkelijke gegevens' : `Correctie ${version.number - 1}`}</h3><Badge variant="muted">{labels[version.status]}</Badge><span class="text-sm text-muted-foreground">{new Date(version.created_at).toLocaleString('nl-NL')}</span></div>
                {#if version.superseded_by_product_id}<p class="text-sm"><a class="text-primary underline" href={`/admin/feed-scanner/products?open=${version.superseded_by_product_id}`}>Dit recept wordt verder beoordeeld als apart product.</a></p>{/if}
                <p>{version.data.brand} · {version.data.name}</p><p class="text-sm">{categories[version.data.category] ?? version.data.category} · {version.data.barcode ?? 'Geen barcode'}</p>
                <p class="whitespace-pre-wrap text-sm">{version.data.composition_text}</p>
                {#each version.data.ingredients ?? [] as row, index}<p class="text-sm">{index + 1}. {row.label ?? ingredientOptions.find(i => i.id === row.ingredient_id)?.name ?? 'Ingrediënt'} {row.amount ? `(${row.amount})` : ''}{row.match_status === 'suggested' ? ' · koppeling nog te controleren' : ''}</p>{/each}
                {#each version.data.nutrients ?? [] as row}<p class="text-sm">{nutrientOptions[row.code]}: {row.value} {row.unit} · {qualifiers[row.qualifier ?? 'exact']} · {bases[row.basis]} {row.source ? `· ${row.source}` : ''}</p>{/each}
                {#if version.data.usage}<p class="whitespace-pre-wrap text-sm">Gebruik: {version.data.usage}</p>{/if}
                {#if version.data.source}<p class="text-sm">Bron: {version.data.source}</p>{/if}
                {#if version.review_notes}<p class="text-sm">Controle: {version.review_notes}</p>{/if}
                {#each version.attachments ?? [] as file}<a href={`/admin/feed-scanner/attachments/${file.id}`} class="block text-sm text-primary underline">{purposes[file.purpose]}: {file.original_name}</a>{/each}
            </section>
        {/each}{/if}
        {#snippet footer()}{#if canPublish && detail && ['draft', 'needs_info'].includes(detail.versions.find(v => v.is_current)?.status) && detail.catalog_status !== 'archived'}<Button variant="outline" onclick={() => reviewProduct(detail, 'needs_info')}>Informatie ontbreekt</Button><Button onclick={() => reviewProduct(detail, 'approve')}>Goedkeuren</Button>{/if}<Button variant="outline" onclick={() => historyOpen = false}>Sluiten</Button>{/snippet}
    </Modal>
</AdminLayout>
