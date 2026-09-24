<script>
    import { onMount } from 'svelte';
    import LibraryThumbnail from '$lib/components/LibraryThumbnail.svelte';
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import StatCard from '$lib/components/StatCard.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { formatDate } from '$lib/utils.js';
    import { Plus, Search, FileText, Star, Trash2 } from '@lucide/svelte';

    let { items, filters, counts, categories } = $props();
    let q = $state(filters.q ?? '');
    let format = $state(filters.format ?? '');
    let gate = $state(filters.gate ?? '');
    let selectedCategories = $state(filters.category_ids ?? []);
    let categorySearch = $state('');
    let moreOpen = $state(false);
    let categoryBar;
    let slots = $state(6);
    const pinned = $derived(categories.filter(c => c.is_quick_filter));
    const quickCategories = $derived([...pinned, ...categories.filter(c => !c.is_quick_filter).slice(0, Math.max(0, slots - pinned.length))]);
    const otherCategories = $derived(categories.filter(c => !quickCategories.some(q => q.id === c.id))
        .filter(c => c.label.toLocaleLowerCase().includes(categorySearch.toLocaleLowerCase()))
        .sort((a, b) => a.label.localeCompare(b.label, 'nl')));
    $effect(() => {
        q = filters.q ?? ''; format = filters.format ?? ''; gate = filters.gate ?? '';
        selectedCategories = filters.category_ids ?? [];
    });
    onMount(() => {
        const observer = new ResizeObserver(([entry]) => { slots = Math.max(1, Math.floor((entry.contentRect.width - 160) / 150)); });
        observer.observe(categoryBar);
        return () => { observer.disconnect(); clearTimeout(timer); };
    });
    function selectCategory(id) {
        selectedCategories = id === null ? [] : selectedCategories.includes(id)
            ? selectedCategories.filter(value => value !== id) : [...selectedCategories, id];
        moreOpen = false;
        apply(0);
    }
    let timer;
    function apply(delay = 250) {
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/library', { q, format, gate, category_ids: selectedCategories }, { preserveState: true, replace: true }), typeof delay === 'number' ? delay : 250);
    }
    function remove(item) {
        if (confirm(`Delete "${item.title}"?`)) router.delete(`/admin/library/${item.id}`);
    }
</script>

<AdminLayout title="Library">
    <PageHeader title="Library CMS" description="Articles, videos, audio, podcasts, courses and programs">
        {#snippet actions()}<Button href="/admin/library/create"><Plus class="size-4" /> New item</Button>{/snippet}
    </PageHeader>

    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <StatCard label="Total items" value={counts.total} icon={FileText} accent="muted" />
        <StatCard label="Drafts" value={counts.drafts} icon={FileText} accent="warning" />
        <StatCard label="Plus-gated" value={counts.plus} icon={Star} accent="primary" />
    </div>

    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Search title…" bind:value={q} oninput={apply} />
                </div>
                <Select class="w-40" bind:value={format} onchange={apply} options={[
                    { value: '', label: 'All formats' }, { value: 'article', label: 'Article' },
                    { value: 'video', label: 'Video' }, { value: 'audio', label: 'Audio' }, { value: 'podcast', label: 'Podcast' }, { value: 'course', label: 'Course' }, { value: 'program', label: 'Program' },
                ]} />
                <Select class="w-40" bind:value={gate} onchange={apply} options={[
                    { value: '', label: 'All' }, { value: 'featured', label: 'Featured' },
                    { value: 'plus', label: 'Plus only' }, { value: 'draft', label: 'Drafts' },
                ]} />
                <details class="relative">
                    <summary class="cursor-pointer rounded-md border px-3 py-2 text-sm">Categorieën {selectedCategories.length ? `(${selectedCategories.length})` : ''}</summary>
                    <div class="absolute right-0 z-20 max-h-80 w-64 overflow-y-auto rounded-lg border bg-card p-3 shadow-lg">
                        {#each [...categories].sort((a, b) => a.label.localeCompare(b.label, 'nl')) as c (c.id)}
                            <label class="flex items-center gap-2 py-2 text-sm"><input type="checkbox" aria-label={c.label} checked={selectedCategories.includes(c.id)} onchange={() => selectCategory(c.id)} /> {c.label} <span class="ml-auto text-muted-foreground">{c.items_count}</span></label>
                        {/each}
                    </div>
                </details>
            </div>
            <div bind:this={categoryBar} class="relative mb-4">
                <div class="flex items-center gap-2">
                    <div class="flex min-w-0 flex-1 items-center gap-2 overflow-x-auto pb-1">
                        <button type="button" aria-pressed={selectedCategories.length === 0} onclick={() => selectCategory(null)} class={'shrink-0 rounded-full border px-3 py-1.5 text-xs ' + (!selectedCategories.length ? 'border-[#18BAB0] bg-[#18BAB0] text-white' : 'hover:bg-accent')}>Alles</button>
                        {#each quickCategories as c (c.id)}
                            <button type="button" aria-pressed={selectedCategories.includes(c.id)} onclick={() => selectCategory(c.id)} class={'shrink-0 rounded-full border px-3 py-1.5 text-xs ' + (selectedCategories.includes(c.id) ? 'border-[#18BAB0] bg-[#18BAB0] text-white' : 'hover:bg-accent')}>{c.label} <span class="opacity-70">{c.items_count}</span></button>
                        {/each}
                    </div>
                    {#if categories.length > quickCategories.length}
                        <button type="button" aria-expanded={moreOpen} onclick={() => (moreOpen = !moreOpen)} class="shrink-0 rounded-full border px-3 py-1.5 text-xs">+ Meer</button>
                    {/if}
                </div>
                {#if moreOpen}
                    <div class="absolute right-0 z-20 mt-1 w-72 rounded-lg border bg-card p-3 shadow-lg" onkeydown={(e) => { if (e.key === 'Escape') moreOpen = false; }} role="group" aria-label="Meer categorieën">
                        <Input placeholder="Zoek categorie" aria-label="Zoek categorie" bind:value={categorySearch} />
                        <div class="mt-2 max-h-64 overflow-y-auto">
                            {#each otherCategories as c (c.id)}
                                <button type="button" aria-pressed={selectedCategories.includes(c.id)} onclick={() => selectCategory(c.id)} class="flex w-full items-center justify-between rounded px-2 py-2 text-left text-sm hover:bg-accent"><span>{c.label}</span><span class="text-muted-foreground">{c.items_count}</span></button>
                            {:else}<p class="p-2 text-sm text-muted-foreground">Geen categorieën gevonden.</p>{/each}
                        </div>
                    </div>
                {/if}
                {#if selectedCategories.length}
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        {#each categories.filter(c => selectedCategories.includes(c.id)) as c (c.id)}
                            <button type="button" aria-label={`${c.label} verwijderen uit filter`} onclick={() => selectCategory(c.id)} class="rounded-full border border-[#18BAB0] bg-[#18BAB0]/10 px-3 py-1 text-xs text-[#108A82]">{c.label} ×</button>
                        {/each}
                        <button type="button" onclick={() => selectCategory(null)} class="ml-auto text-xs text-muted-foreground underline">Wis categorieën</button>
                    </div>
                {/if}
            </div>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Title</TableHead>
                        <TableHead>Format</TableHead>
                        <TableHead>Author</TableHead>
                        <TableHead>Flags</TableHead>
                        <TableHead>Published</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {#each items.data as i (i.id)}
                        <TableRow>
                            <TableCell><Link href={`/admin/library/${i.id}/edit`} class="flex items-center gap-3 font-medium hover:text-primary"><LibraryThumbnail src={i.hero_image_url} format={i.format} class="w-20 shrink-0" />{i.title}</Link></TableCell>
                            <TableCell><Badge variant="secondary">{i.format}</Badge></TableCell>
                            <TableCell class="text-muted-foreground">{i.author ?? '—'}</TableCell>
                            <TableCell>
                                <div class="flex gap-1">
                                    {#if i.is_featured}<Badge>featured</Badge>{/if}
                                    {#if i.is_plus}<Badge variant="warning">plus</Badge>{/if}
                                </div>
                            </TableCell>
                            <TableCell>{#if i.published}<span class="text-muted-foreground">{formatDate(i.published_at)}</span>{:else}<Badge variant="muted">draft</Badge>{/if}</TableCell>
                            <TableCell><Button size="sm" variant="ghost" onclick={() => remove(i)}><Trash2 class="size-4 text-destructive" /></Button></TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="6" class="py-8 text-center text-muted-foreground">No items.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={items} />
        </CardContent>
    </Card>
</AdminLayout>
