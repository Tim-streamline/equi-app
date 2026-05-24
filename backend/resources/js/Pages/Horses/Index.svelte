<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardContent, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { Search } from '@lucide/svelte';

    let { horses, filters } = $props();
    let q = $state(filters.q ?? '');
    let status = $state(filters.status ?? '');
    let timer;
    function apply() {
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/horses', { q, status }, { preserveState: true, replace: true }), 250);
    }
</script>

<AdminLayout title="Horses">
    <PageHeader title="Horses" description={`${horses.total} horses`} />
    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Search name, breed, stable…" bind:value={q} oninput={apply} />
                </div>
                <Select class="w-44" bind:value={status} onchange={apply} options={[
                    { value: '', label: 'All statuses' },
                    { value: 'active', label: 'Active' },
                    { value: 'archived', label: 'Archived' },
                ]} />
            </div>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Owner</TableHead>
                        <TableHead>Breed / sex</TableHead>
                        <TableHead>Obs</TableHead>
                        <TableHead>Shares</TableHead>
                        <TableHead>Status</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {#each horses.data as h (h.id)}
                        <TableRow class="cursor-pointer" onclick={() => router.visit(`/admin/horses/${h.id}`)}>
                            <TableCell class="font-medium">{h.name}</TableCell>
                            <TableCell class="text-muted-foreground">{h.owner?.name ?? '—'}</TableCell>
                            <TableCell>{h.breed ?? '—'} {h.sex ? `· ${h.sex}` : ''}</TableCell>
                            <TableCell>{h.observations_count}</TableCell>
                            <TableCell>{h.shares_count}</TableCell>
                            <TableCell><Badge variant={statusVariant(h.status)}>{h.status}</Badge></TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="6" class="py-8 text-center text-muted-foreground">No horses found.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={horses} />
        </CardContent>
    </Card>
</AdminLayout>
