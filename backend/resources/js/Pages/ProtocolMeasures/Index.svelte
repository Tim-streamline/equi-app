<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { page, router, useForm } from '@inertiajs/svelte';
    import { Badge, Button, Input, Select, Textarea } from '$lib/components/ui';
    import {
        Archive, ArchiveRestore, ArrowDown, ArrowUp, Dumbbell, Image as ImageIcon, Leaf, Pencil, Plus, Search, Settings2, Trash2, X,
    } from '@lucide/svelte';
    import { measureMatches, protocolCount } from '$lib/protocolMeasures.js';

    let { measures, categories } = $props();

    const icons = { voeding: Leaf, management: Settings2, beweging: Dumbbell };
    const statusOptions = [
        { value: 'active', label: 'Actief' },
        { value: 'archived', label: 'Gearchiveerd' },
        { value: 'all', label: 'Alle' },
    ];

    let query = $state('');
    let categoryFilter = $state('all');
    let statusFilter = $state('active');

    let formOpen = $state(false);
    let editing = $state(null);
    let iconPreview = $state(null);
    let iconInputKey = $state(0);

    let pendingArchive = $state(null);
    let archiveOpen = $state(false);
    let pendingDelete = $state(null);
    let deleteOpen = $state(false);
    let processing = $state(false);

    const pageError = $derived($page.props.errors?.measure ?? null);
    const categoryByKey = $derived(Object.fromEntries(categories.map((category) => [category.key, category])));

    const statusMatches = (measure) => statusFilter === 'all'
        || (statusFilter === 'archived' ? Boolean(measure.archived_at) : !measure.archived_at);
    const visible = $derived(measures.filter((measure) => statusMatches(measure) && measureMatches(measure, query)));
    const counts = $derived(Object.fromEntries(categories.map((category) => [
        category.key,
        visible.filter((measure) => measure.category === category.key).length,
    ])));
    const sections = $derived(categories
        .filter((category) => categoryFilter === 'all' || category.key === categoryFilter)
        .map((category) => ({ ...category, items: visible.filter((measure) => measure.category === category.key) })));
    // Moving up/down acts on the full active list of a category, so it is offered only when that list is on screen.
    const canReorder = $derived(statusFilter === 'active' && query.trim() === '');

    const form = useForm({ category: 'voeding', title: '', description: '', layout: 'normal', icon: null, remove_icon: false });
    const formLayouts = $derived(categoryByKey[$form.category]?.layouts ?? []);
    const categoryLocked = $derived(Boolean(editing && editing.usage_count > 0));

    function layoutLabel(measure) {
        return categoryByKey[measure.category]?.layouts.find((option) => option.value === measure.layout)?.label ?? measure.layout;
    }

    function openForm(measure = null, category = null) {
        editing = measure;
        $form.defaults({
            category: measure?.category ?? category ?? (categoryFilter === 'all' ? 'voeding' : categoryFilter),
            title: measure?.title ?? '',
            description: measure?.description ?? '',
            layout: measure?.layout ?? 'normal',
            icon: null,
            remove_icon: false,
        });
        $form.reset();
        $form.clearErrors();
        iconPreview = measure?.icon_url ?? null;
        iconInputKey += 1;
        formOpen = true;
    }

    function changeCategory() {
        if (!formLayouts.some((option) => option.value === $form.layout)) {
            $form.layout = 'normal';
        }
    }

    function selectIcon(event) {
        const file = event.currentTarget.files?.[0] ?? null;
        $form.icon = file;
        $form.remove_icon = false;
        iconPreview = file ? URL.createObjectURL(file) : editing?.icon_url ?? null;
    }

    function removeIcon() {
        $form.icon = null;
        $form.remove_icon = true;
        iconPreview = null;
        iconInputKey += 1;
    }

    function submit(event) {
        event.preventDefault();
        const options = { preserveScroll: true, forceFormData: true, onSuccess: () => (formOpen = false) };

        if (editing) {
            $form
                .transform((data) => ({ ...data, _method: 'put' }))
                .post(`/admin/protocol-measures/${editing.category}/${editing.id}`, options);
        } else {
            $form.transform((data) => data).post('/admin/protocol-measures', options);
        }
    }

    function moveMeasure(measure, direction) {
        router.patch(`/admin/protocol-measures/${measure.category}/${measure.id}/move`, { direction }, { preserveScroll: true, preserveState: true });
    }

    function restoreMeasure(measure) {
        router.post(`/admin/protocol-measures/${measure.category}/${measure.id}/restore`, {}, { preserveScroll: true, preserveState: true });
    }

    function confirmArchive(measure) {
        pendingArchive = measure;
        archiveOpen = true;
    }

    function confirmDelete(measure) {
        pendingDelete = measure;
        deleteOpen = true;
    }

    function archiveMeasure() {
        if (!pendingArchive) return;
        processing = true;
        router.post(`/admin/protocol-measures/${pendingArchive.category}/${pendingArchive.id}/archive`, {}, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (processing = false),
            onSuccess: () => {
                archiveOpen = false;
                pendingArchive = null;
            },
        });
    }

    function deleteMeasure() {
        if (!pendingDelete) return;
        processing = true;
        router.delete(`/admin/protocol-measures/${pendingDelete.category}/${pendingDelete.id}`, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                processing = false;
                deleteOpen = false;
                pendingDelete = null;
            },
        });
    }

    function clearFilters() {
        query = '';
        categoryFilter = 'all';
        statusFilter = 'active';
    }
</script>

<AdminLayout title="Protocolmaatregelen">
    <PageHeader
        title="Protocolmaatregelen"
        description="De standaardmaatregelen die je in de protocol-editor kiest onder Voeding, Management en Beweging."
    >
        {#snippet actions()}
            <Button onclick={() => openForm()}><Plus class="size-4" /> Nieuwe maatregel</Button>
        {/snippet}
    </PageHeader>

    {#if pageError}
        <div role="alert" class="mb-4 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">{pageError}</div>
    {/if}

    <div class="mb-4 flex flex-col gap-3 rounded-xl border bg-card p-4 lg:flex-row lg:items-center">
        <div class="relative min-w-0 flex-1">
            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <Input bind:value={query} type="search" placeholder="Zoek op titel of omschrijving" aria-label="Zoek maatregelen" class="pl-9" />
        </div>
        <div class="flex gap-1.5 overflow-x-auto" role="group" aria-label="Categorie">
            <button type="button" aria-pressed={categoryFilter === 'all'} onclick={() => (categoryFilter = 'all')}
                class={'shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium ' + (categoryFilter === 'all' ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent')}>
                Alle <span class="opacity-70">{visible.length}</span>
            </button>
            {#each categories as category (category.key)}
                {@const Icon = icons[category.key]}
                <button type="button" aria-pressed={categoryFilter === category.key} onclick={() => (categoryFilter = category.key)}
                    class={'flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium ' + (categoryFilter === category.key ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent')}>
                    <Icon class="size-3.5" aria-hidden="true" /> {category.label} <span class="opacity-70">{counts[category.key]}</span>
                </button>
            {/each}
        </div>
        <Select bind:value={statusFilter} options={statusOptions} aria-label="Status" class="lg:w-40" />
    </div>

    {#snippet measureRow(measure, index, items)}
        <article class="group flex items-start gap-4 border-b px-5 py-4 last:border-b-0 sm:px-6" class:opacity-70={measure.archived_at}>
            <div class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-primary/10 text-primary">
                {#if measure.icon_url}
                    <img src={measure.icon_url} alt="" class="size-full object-cover" />
                {:else}
                    <ImageIcon class="size-5" aria-hidden="true" />
                {/if}
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h4 class="font-semibold text-foreground">{measure.title}</h4>
                    {#if measure.layout !== 'normal'}<Badge variant="muted">{layoutLabel(measure)}</Badge>{/if}
                    {#if measure.archived_at}<Badge variant="warning">Gearchiveerd</Badge>{/if}
                    <Badge variant={measure.usage_count ? 'secondary' : 'outline'}>{measure.usage_count ? `In ${protocolCount(measure.usage_count)}` : 'Niet gebruikt'}</Badge>
                </div>
                <p class="mt-1 line-clamp-3 max-w-3xl whitespace-pre-line text-sm leading-6 text-muted-foreground">{measure.description}</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center justify-end gap-1">
                {#if canReorder && !measure.archived_at}
                    <Button variant="ghost" size="icon" disabled={index === 0} onclick={() => moveMeasure(measure, 'up')} aria-label={`${measure.title} omhoog`} title="Omhoog">
                        <ArrowUp class="size-4" />
                    </Button>
                    <Button variant="ghost" size="icon" disabled={index === items.length - 1} onclick={() => moveMeasure(measure, 'down')} aria-label={`${measure.title} omlaag`} title="Omlaag">
                        <ArrowDown class="size-4" />
                    </Button>
                {/if}
                <Button variant="ghost" size="icon" onclick={() => openForm(measure)} aria-label={`${measure.title} bewerken`} title="Bewerken">
                    <Pencil class="size-4" />
                </Button>
                {#if measure.archived_at}
                    <Button variant="ghost" size="icon" onclick={() => restoreMeasure(measure)} aria-label={`${measure.title} herstellen`} title="Herstellen">
                        <ArchiveRestore class="size-4" />
                    </Button>
                {:else}
                    <Button variant="ghost" size="icon" onclick={() => confirmArchive(measure)} aria-label={`${measure.title} archiveren`} title="Archiveren">
                        <Archive class="size-4" />
                    </Button>
                {/if}
                <Button
                    variant="ghost"
                    size="icon"
                    disabled={measure.usage_count > 0}
                    onclick={() => confirmDelete(measure)}
                    aria-label={`${measure.title} verwijderen`}
                    title={measure.usage_count > 0 ? `Gebruikt in ${protocolCount(measure.usage_count)}: archiveer in plaats van verwijderen` : 'Verwijderen'}
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>
        </article>
    {/snippet}

    {#if visible.length === 0}
        <div class="flex min-h-[260px] flex-col items-center justify-center rounded-xl border bg-card px-6 text-center">
            <div class="flex size-11 items-center justify-center rounded-full bg-primary/10 text-primary"><Search class="size-5" /></div>
            <h4 class="mt-4 font-semibold">Geen maatregelen gevonden</h4>
            <p class="mt-1 max-w-sm text-sm text-muted-foreground">Pas je zoekterm of filters aan, of voeg een nieuwe maatregel toe.</p>
            <div class="mt-4 flex gap-2">
                <Button variant="outline" onclick={clearFilters}>Filters wissen</Button>
                <Button onclick={() => openForm()}><Plus class="size-4" /> Nieuwe maatregel</Button>
            </div>
        </div>
    {:else}
        <div class="space-y-4">
            {#each sections as section (section.key)}
                {@const Icon = icons[section.key]}
                {#if section.items.length || categoryFilter !== 'all'}
                    <section class="overflow-hidden rounded-xl border bg-card" aria-labelledby={`measures-${section.key}`}>
                        <header class="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/30 px-5 py-4 sm:px-6">
                            <div class="flex items-center gap-3">
                                <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"><Icon class="size-4" aria-hidden="true" /></div>
                                <div>
                                    <h3 id={`measures-${section.key}`} class="font-semibold">{section.label} <span class="font-normal text-muted-foreground">({section.items.length})</span></h3>
                                    <p class="text-sm text-muted-foreground">{section.description}</p>
                                </div>
                            </div>
                            <Button variant="outline" size="sm" onclick={() => openForm(null, section.key)}><Plus class="size-4" /> Toevoegen</Button>
                        </header>
                        {#if section.items.length}
                            {#each section.items as measure, index (measure.id)}
                                {@render measureRow(measure, index, section.items)}
                            {/each}
                        {:else}
                            <p class="px-6 py-10 text-center text-sm text-muted-foreground">Geen maatregelen die aan je filters voldoen.</p>
                        {/if}
                    </section>
                {/if}
            {/each}
        </div>
    {/if}

    <Modal
        bind:open={formOpen}
        title={editing ? 'Maatregel bewerken' : 'Nieuwe maatregel'}
        description={`Beschikbaar in de protocol-editor onder ${categoryByKey[$form.category]?.label ?? ''}.`}
    >
        <form id="protocol-measure-form" onsubmit={submit} class="space-y-4">
            <Field label="Categorie" error={$form.errors.category}>
                <Select bind:value={$form.category} options={categories.map((category) => ({ value: category.key, label: category.label }))} onchange={changeCategory} disabled={categoryLocked} aria-label="Categorie" />
                {#if categoryLocked}
                    <p class="mt-1 text-xs text-muted-foreground">Gebruikt in {protocolCount(editing.usage_count)}, daarom kan de categorie niet meer wijzigen. Archiveer deze maatregel en maak een nieuwe aan in de andere categorie.</p>
                {/if}
            </Field>
            <Field label="Titel" error={$form.errors.title}>
                <Input bind:value={$form.title} aria-label="Titel" autofocus placeholder="Bijvoorbeeld: Onbeperkt ruwvoer" />
            </Field>
            <Field label="Omschrijving" error={$form.errors.description}>
                <Textarea bind:value={$form.description} aria-label="Omschrijving" rows="6" placeholder="Beschrijf de maatregel concreet en toepasbaar." />
            </Field>
            <Field label="Layout" error={$form.errors.layout}>
                <Select bind:value={$form.layout} options={formLayouts} aria-label="Layout" />
                <p class="mt-1 text-xs text-muted-foreground">Bepaalt hoe deze maatregel in de app wordt weergegeven.</p>
            </Field>
            {#if editing && editing.usage_count > 0}
                <p class="rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">Protocollen die deze maatregel al gebruiken houden hun eigen kopie van titel en omschrijving. Wijzigingen gelden voor nieuwe selecties.</p>
            {/if}
            <Field label="Icoon" error={$form.errors.icon}>
                <div class="flex items-center gap-3 rounded-lg border border-dashed p-3">
                    <div class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-muted text-muted-foreground">
                        {#if iconPreview}
                            <img src={iconPreview} alt="Icoonvoorbeeld" class="size-full object-cover" />
                        {:else}
                            <ImageIcon class="size-5" aria-hidden="true" />
                        {/if}
                    </div>
                    <div class="min-w-0 flex-1">
                        {#key iconInputKey}
                            <input type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,image/*" onchange={selectIcon} aria-label="Icoon kiezen" class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:font-medium file:text-primary" />
                        {/key}
                        <p class="mt-1 text-xs text-muted-foreground">JPG, PNG, WebP, GIF of SVG · maximaal 2 MB.</p>
                    </div>
                    {#if iconPreview}
                        <Button type="button" variant="ghost" size="icon" onclick={removeIcon} aria-label="Icoon verwijderen"><X class="size-4" /></Button>
                    {/if}
                </div>
            </Field>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (formOpen = false)}>Annuleren</Button>
            <Button type="submit" form="protocol-measure-form" disabled={$form.processing}>
                {editing ? 'Wijzigingen opslaan' : 'Maatregel toevoegen'}
            </Button>
        {/snippet}
    </Modal>

    <Modal bind:open={archiveOpen} title="Maatregel archiveren" description="Je kunt de maatregel later herstellen." size="sm">
        <p class="text-sm text-muted-foreground">
            <strong class="text-foreground">{pendingArchive?.title}</strong> is daarna niet meer te kiezen in de protocol-editor.
            Protocollen die hem al gebruiken behouden hem.
        </p>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (archiveOpen = false)}>Annuleren</Button>
            <Button disabled={processing} onclick={archiveMeasure}>Archiveren</Button>
        {/snippet}
    </Modal>

    <Modal bind:open={deleteOpen} title="Maatregel verwijderen" description="Dit kan niet ongedaan worden gemaakt." size="sm">
        <p class="text-sm text-muted-foreground">
            <strong class="text-foreground">{pendingDelete?.title}</strong> wordt definitief verwijderd. Hij wordt in geen enkel protocol gebruikt.
        </p>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (deleteOpen = false)}>Annuleren</Button>
            <Button variant="destructive" disabled={processing} onclick={deleteMeasure}>Verwijderen</Button>
        {/snippet}
    </Modal>
</AdminLayout>
