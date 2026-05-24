<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import { router } from '@inertiajs/svelte';
    import { Card, CardContent, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { formatDate } from '$lib/utils.js';
    import { Search, Flag } from '@lucide/svelte';

    let { scans, filters, flaggedCount } = $props();
    let q = $state(filters.q ?? '');
    let rating = $state(filters.rating ?? '');
    let flagged = $state(!!filters.flagged);
    let timer;
    function apply() {
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/scans', { q, rating, flagged: flagged ? 1 : undefined }, { preserveState: true, replace: true }), 250);
    }
</script>

<AdminLayout title="Scan results">
    <PageHeader title="Scan results" description={`${scans.total} scans · ${flaggedCount} flagged`} />
    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Search product or brand…" bind:value={q} oninput={apply} />
                </div>
                <Select class="w-40" bind:value={rating} onchange={apply} options={[
                    { value: '', label: 'All ratings' }, { value: 'Goed', label: 'Goed' }, { value: 'Matig', label: 'Matig' }, { value: 'Slecht', label: 'Slecht' }]} />
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={flagged} onchange={apply} class="size-4 rounded border-input" /> Flagged only</label>
            </div>
            <Table>
                <TableHeader><TableRow><TableHead>Product</TableHead><TableHead>User</TableHead><TableHead>Score</TableHead><TableHead>Rating</TableHead><TableHead>When</TableHead><TableHead></TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each scans.data as s (s.id)}
                        <TableRow class="cursor-pointer" onclick={() => router.visit(`/admin/scans/${s.id}`)}>
                            <TableCell><div class="font-medium">{s.product_name ?? 'Unknown'}</div><div class="text-xs text-muted-foreground">{s.brand}</div></TableCell>
                            <TableCell class="text-muted-foreground">{s.user?.name}</TableCell>
                            <TableCell>{s.score}</TableCell>
                            <TableCell><Badge variant={statusVariant(s.rating)}>{s.rating}</Badge></TableCell>
                            <TableCell class="text-muted-foreground">{formatDate(s.scanned_at)}</TableCell>
                            <TableCell>{#if s.flagged}<Flag class="size-4 text-destructive" />{/if}</TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="6" class="py-8 text-center text-muted-foreground">No scans.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={scans} />
        </CardContent>
    </Card>
</AdminLayout>
