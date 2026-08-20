<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import StatCard from '$lib/components/StatCard.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { formatDate } from '$lib/utils.js';
    import { Plus, Search, FileText, Star, Trash2 } from '@lucide/svelte';

    let { items, filters, counts } = $props();
    let q = $state(filters.q ?? '');
    let format = $state(filters.format ?? '');
    let gate = $state(filters.gate ?? '');
    let timer;
    function apply() {
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/library', { q, format, gate }, { preserveState: true, replace: true }), 250);
    }
    function remove(item) {
        if (confirm(`Delete "${item.title}"?`)) router.delete(`/admin/library/${item.id}`);
    }
</script>

<AdminLayout title="Library">
    <PageHeader title="Library CMS" description="Articles, videos, courses and programs">
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
                    { value: 'video', label: 'Video' }, { value: 'course', label: 'Course' }, { value: 'program', label: 'Program' },
                ]} />
                <Select class="w-40" bind:value={gate} onchange={apply} options={[
                    { value: '', label: 'All' }, { value: 'featured', label: 'Featured' },
                    { value: 'plus', label: 'Plus only' }, { value: 'draft', label: 'Drafts' },
                ]} />
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
                            <TableCell><Link href={`/admin/library/${i.id}/edit`} class="font-medium hover:text-primary">{i.title}</Link></TableCell>
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
