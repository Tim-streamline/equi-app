<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import { router } from '@inertiajs/svelte';
    import { Card, CardContent, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { formatDateTime } from '$lib/utils.js';
    import { Search } from '@lucide/svelte';

    let { logs, filters, actions } = $props();
    let q = $state(filters.q ?? '');
    let action = $state(filters.action ?? '');
    let timer;
    function apply() {
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/audit-log', { q, action }, { preserveState: true, replace: true }), 250);
    }
</script>

<AdminLayout title="Audit log">
    <PageHeader title="Audit log" description="Every privileged admin mutation, with before/after values" />
    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Search actor or target…" bind:value={q} oninput={apply} />
                </div>
                <Select class="w-48" bind:value={action} onchange={apply} options={[{ value: '', label: 'All actions' }, ...actions.map((a) => ({ value: a, label: a }))]} />
            </div>
            <Table>
                <TableHeader><TableRow><TableHead>When</TableHead><TableHead>Actor</TableHead><TableHead>Action</TableHead><TableHead>Target</TableHead><TableHead>Reason</TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each logs.data as log (log.id)}
                        <TableRow>
                            <TableCell class="whitespace-nowrap text-muted-foreground">{formatDateTime(log.created_at)}</TableCell>
                            <TableCell>{log.actor_name ?? 'system'}</TableCell>
                            <TableCell><Badge variant="muted" class="capitalize">{log.action.replace(/_/g, ' ')}</Badge></TableCell>
                            <TableCell>{log.target_type ?? '—'}{#if log.target_label}<span class="text-muted-foreground"> · {log.target_label}</span>{/if}</TableCell>
                            <TableCell class="max-w-xs truncate text-muted-foreground">{log.reason ?? ''}</TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="5" class="py-8 text-center text-muted-foreground">No audit entries.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={logs} />
        </CardContent>
    </Card>
</AdminLayout>
