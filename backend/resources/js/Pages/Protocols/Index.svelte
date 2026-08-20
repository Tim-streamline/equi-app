<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import { router } from '@inertiajs/svelte';
    import { Button, Card, CardContent, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { ArrowUpRight, Plus, Search } from '@lucide/svelte';

    let { protocols, filters } = $props();
    let q = $state(filters.q ?? '');
    let status = $state(filters.status ?? '');
    let timer;
    function apply() {
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
                    <Input class="pl-9" placeholder="Search protocol type or horse…" bind:value={q} oninput={apply} />
                </div>
                <Select class="w-44" bind:value={status} onchange={apply} options={[
                    { value: '', label: 'All' }, { value: 'active', label: 'Active' }, { value: 'paused', label: 'Paused' }, { value: 'completed', label: 'Completed' }]} />
            </div>
            <Table>
                <TableHeader><TableRow><TableHead>Protocol type</TableHead><TableHead>Horse</TableHead><TableHead>Owner</TableHead><TableHead>Therapist</TableHead><TableHead>Week</TableHead><TableHead>Current phase</TableHead><TableHead>Status</TableHead><TableHead><span class="sr-only">Open</span></TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each protocols.data as p (p.id)}
                        <TableRow class="group cursor-pointer" onclick={() => router.visit(`/admin/protocols/${p.id}/edit`)}>
                            <TableCell class="font-medium">{p.protocol_type?.name ?? '—'}</TableCell>
                            <TableCell class="text-muted-foreground">{p.horse?.name}</TableCell>
                            <TableCell class="text-muted-foreground">{p.horse?.owner?.name ?? '—'}</TableCell>
                            <TableCell class="text-muted-foreground">{p.therapist?.name ?? '—'}</TableCell>
                            <TableCell>{p.current_week ?? '?'}/{p.total_weeks ?? '?'}</TableCell>
                            <TableCell class="text-muted-foreground">{p.current_phase?.title ?? '—'}</TableCell>
                            <TableCell><Badge variant={statusVariant(p.status)}>{p.status}</Badge></TableCell>
                            <TableCell class="text-right"><ArrowUpRight class="ml-auto size-4 text-muted-foreground transition group-hover:text-primary" /></TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="8" class="py-12 text-center text-muted-foreground">
                            No protocols found. <a href="/admin/protocols/create" class="font-medium text-primary hover:underline">Create the first one.</a>
                        </TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={protocols} />
        </CardContent>
    </Card>
</AdminLayout>
