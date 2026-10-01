<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import { untrack } from 'svelte';
    import { router, usePage } from '@inertiajs/svelte';
    import { Button, Card, CardContent, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { ArrowUpRight, Plus, Search } from '@lucide/svelte';

    let { protocols, filters, selectionIds } = $props();
    let q = $state(filters.q ?? '');
    let status = $state(filters.status ?? '');
    const page = usePage();
    const canManage = $derived(['owner', 'admin', 'therapist_admin'].includes($page.props.auth?.user?.role));
    let timer;
    let selected = $state([]);
    let busy = $state(false);
    let failure = $state('');
    const allSelected = $derived(selectionIds.length > 0 && selectionIds.every((id) => selected.includes(id)));
    $effect(() => { const allowed = selectionIds; selected = untrack(() => selected.filter((id) => allowed.includes(id))); });
    function toggle(id) { selected = selected.includes(id) ? selected.filter((value) => value !== id) : [...selected, id]; }
    function bulk(action) {
        const ids = [...selected];
        if (action === 'delete' && !window.confirm(`${ids.length} geselecteerde protocollen definitief verwijderen? Alle bijbehorende protocolgegevens worden verwijderd. Dit kan niet ongedaan worden gemaakt.`)) return;
        failure = '';
        busy = true;
        router.post('/admin/protocols/bulk', { action, ids, confirmed: action === 'delete' }, {
            preserveScroll: true,
            onSuccess: () => { selected = []; },
            onError: (errors) => { failure = Object.values(errors).join(' '); },
            onFinish: () => { busy = false; },
        });
    }
    function apply() {
        selected = [];
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/protocols', { q, status }, { preserveState: true, replace: true }), 250);
    }
</script>

<AdminLayout title="Protocols">
    <PageHeader title="Protocols" description={`${protocols.total} protocols assigned to horses`}>
        {#snippet actions()}<Button href="/admin/protocols/create"><Plus class="size-4" /> New protocol</Button>{/snippet}
    </PageHeader>
    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Search protocol template or horse…" bind:value={q} oninput={apply} />
                </div>
                <Select class="w-44" bind:value={status} onchange={apply} options={[
                    { value: '', label: 'All' }, { value: 'active', label: 'Active' }, { value: 'paused', label: 'Paused' }, { value: 'completed', label: 'Completed' }, { value: 'archived', label: 'Gearchiveerd' }]} />
            </div>
            {#if selected.length}
                <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border bg-muted/30 p-3" role="region" aria-label="Bulkacties">
                    <span class="mr-auto text-sm font-semibold">{selected.length} protocollen geselecteerd</span>
                    {#if status === 'archived'}<Button disabled={busy} onclick={() => bulk('restore')}>Herstellen</Button>
                    {:else}<Button disabled={busy} onclick={() => bulk('archive')}>Archiveren</Button>{/if}
                    <Button variant="destructive" disabled={busy} onclick={() => bulk('delete')}>Verwijderen</Button>
                </div>
            {/if}
            {#if failure}<p role="alert" class="mb-3 text-sm text-destructive">{failure}</p>{/if}
            <Table>
                <TableHeader><TableRow><TableHead><input type="checkbox" aria-label="Alle protocollen binnen de huidige filtering selecteren" checked={allSelected} indeterminate={selected.length > 0 && !allSelected} disabled={!canManage || busy || !selectionIds.length} onchange={() => { selected = allSelected ? [] : [...selectionIds]; }} /></TableHead><TableHead>Protocol template</TableHead><TableHead>Horse</TableHead><TableHead>Owner</TableHead><TableHead>Therapist</TableHead><TableHead>Week</TableHead><TableHead>Current phase</TableHead><TableHead>State</TableHead><TableHead>Publication</TableHead><TableHead><span class="sr-only">Open</span></TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each protocols.data as p (p.id)}
                        <TableRow class="group cursor-pointer" onclick={() => router.visit(`/admin/protocols/${p.id}/edit`)}>
                            <TableCell onclick={(event) => event.stopPropagation()}><input type="checkbox" aria-label={`Selecteer protocol ${p.title}`} checked={selected.includes(p.id)} disabled={!canManage || busy} onchange={() => toggle(p.id)} /></TableCell>
                            <TableCell class="font-medium">
                                <span>{p.protocol_template_name ?? p.protocol_template?.name ?? '—'}</span>
                            </TableCell>
                            <TableCell class="text-muted-foreground">{p.horse?.name}</TableCell>
                            <TableCell class="text-muted-foreground">{p.horse?.owner?.name ?? '—'}</TableCell>
                            <TableCell class="text-muted-foreground">{p.therapist?.name ?? '—'}</TableCell>
                            <TableCell>{p.current_week ?? '?'}/{p.total_weeks ?? '?'}</TableCell>
                            <TableCell class="text-muted-foreground">{p.current_phase?.title ?? '—'}</TableCell>
                            <TableCell><Badge variant={statusVariant(p.status)}>{p.status === 'archived' ? 'Gearchiveerd' : p.status}</Badge></TableCell>
                            <TableCell><Badge variant={p.published_at ? 'success' : 'muted'}>{p.published_at ? 'Published' : 'Draft'}</Badge></TableCell>
                            <TableCell class="text-right"><ArrowUpRight class="ml-auto size-4 text-muted-foreground transition group-hover:text-primary" /></TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="10" class="py-12 text-center text-muted-foreground">
                            No protocols found. <a href="/admin/protocols/create" class="font-medium text-primary hover:underline">Create the first one.</a>
                        </TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={protocols} preserveState />
        </CardContent>
    </Card>
</AdminLayout>
