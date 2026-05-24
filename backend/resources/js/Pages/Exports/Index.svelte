<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import StatCard from '$lib/components/StatCard.svelte';
    import { router } from '@inertiajs/svelte';
    import { Card, CardContent, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { formatDateTime } from '$lib/utils.js';
    import { Clock, CheckCircle2, ExternalLink } from '@lucide/svelte';

    let { exports, filters, counts } = $props();
    let status = $state(filters.status ?? '');
    function apply() { router.get('/admin/exports', { status }, { preserveState: true, replace: true }); }
</script>

<AdminLayout title="Data exports">
    <PageHeader title="Data exports" description="Privacy & compliance — CSV/PDF export requests" />
    <div class="mb-4 grid gap-4 sm:grid-cols-2">
        <StatCard label="Pending" value={counts.pending} icon={Clock} accent="warning" />
        <StatCard label="Completed" value={counts.completed} icon={CheckCircle2} accent="success" />
    </div>
    <Card>
        <CardContent class="p-4">
            <div class="mb-4">
                <Select class="w-44" bind:value={status} onchange={apply} options={[
                    { value: '', label: 'All' }, { value: 'pending', label: 'Pending' }, { value: 'completed', label: 'Completed' }]} />
            </div>
            <Table>
                <TableHeader><TableRow><TableHead>User</TableHead><TableHead>Format</TableHead><TableHead>Requested</TableHead><TableHead>Status</TableHead><TableHead>File</TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each exports.data as ex (ex.id)}
                        <TableRow>
                            <TableCell>{ex.user?.name ?? '—'}{#if ex.horse}<span class="text-muted-foreground"> · {ex.horse.name}</span>{/if}</TableCell>
                            <TableCell class="uppercase">{ex.format}</TableCell>
                            <TableCell class="text-muted-foreground">{formatDateTime(ex.requested_at)}</TableCell>
                            <TableCell><Badge variant={ex.completed_at ? 'success' : 'warning'}>{ex.completed_at ? 'completed' : 'pending'}</Badge></TableCell>
                            <TableCell>{#if ex.file_url}<a href={ex.file_url} target="_blank" class="inline-flex items-center gap-1 text-primary"><ExternalLink class="size-4" /> Download</a>{:else}—{/if}</TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="5" class="py-8 text-center text-muted-foreground">No export requests.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={exports} />
        </CardContent>
    </Card>
</AdminLayout>
