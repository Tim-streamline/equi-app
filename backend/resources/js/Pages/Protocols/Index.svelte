<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import { router } from '@inertiajs/svelte';
    import { Card, CardContent, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { Search } from '@lucide/svelte';

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
    <PageHeader title="Protocols" description={`${protocols.total} protocols assigned to horses`} />
    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Search title…" bind:value={q} oninput={apply} />
                </div>
                <Select class="w-44" bind:value={status} onchange={apply} options={[
                    { value: '', label: 'All' }, { value: 'active', label: 'Active' }, { value: 'paused', label: 'Paused' }, { value: 'completed', label: 'Completed' }]} />
            </div>
            <Table>
                <TableHeader><TableRow><TableHead>Title</TableHead><TableHead>Horse</TableHead><TableHead>Therapist</TableHead><TableHead>Week</TableHead><TableHead>Tasks</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each protocols.data as p (p.id)}
                        <TableRow class="cursor-pointer" onclick={() => router.visit(`/admin/protocols/${p.id}`)}>
                            <TableCell class="font-medium">{p.title}</TableCell>
                            <TableCell class="text-muted-foreground">{p.horse?.name}</TableCell>
                            <TableCell class="text-muted-foreground">{p.therapist?.name ?? '—'}</TableCell>
                            <TableCell>{p.current_week ?? '?'}/{p.total_weeks ?? '?'}</TableCell>
                            <TableCell>{p.tasks_count}</TableCell>
                            <TableCell><Badge variant={statusVariant(p.status)}>{p.status}</Badge></TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="6" class="py-8 text-center text-muted-foreground">No protocols.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={protocols} />
        </CardContent>
    </Card>
</AdminLayout>
