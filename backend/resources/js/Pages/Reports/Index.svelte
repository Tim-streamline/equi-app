<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardContent, Select, Badge, Button, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { formatDate } from '$lib/utils.js';

    let { reports, filters } = $props();
    let status = $state(filters.status ?? 'open');
    function apply() { router.get('/admin/reports', { status }, { preserveState: true, replace: true }); }
    function resolve(report, decision) { router.post(`/admin/reports/${report.id}/resolve`, { status: decision }); }
</script>

<AdminLayout title="Reports">
    <PageHeader title="Moderation reports" description="User-submitted reports on posts and replies" />
    <Card>
        <CardContent class="p-4">
            <div class="mb-4">
                <Select class="w-48" bind:value={status} onchange={apply} options={[
                    { value: 'open', label: 'Open' }, { value: 'reviewing', label: 'Reviewing' },
                    { value: 'resolved', label: 'Resolved' }, { value: 'dismissed', label: 'Dismissed' }]} />
            </div>
            <Table>
                <TableHeader><TableRow><TableHead>Reason</TableHead><TableHead>Content</TableHead><TableHead>Reporter</TableHead><TableHead>When</TableHead><TableHead></TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each reports.data as r (r.id)}
                        <TableRow>
                            <TableCell><Badge variant="destructive">{r.reason}</Badge></TableCell>
                            <TableCell class="max-w-sm">
                                <Link href={`/admin/community/post/${r.post_id}`} class="line-clamp-1 hover:text-primary">{r.subject_excerpt}</Link>
                                <div class="text-xs text-muted-foreground">{r.subject_type}</div>
                            </TableCell>
                            <TableCell class="text-muted-foreground">{r.reporter_name ?? '—'}</TableCell>
                            <TableCell class="text-muted-foreground">{formatDate(r.created_at)}</TableCell>
                            <TableCell class="text-right">
                                {#if r.status === 'open' || r.status === 'reviewing'}
                                    <Button size="sm" variant="outline" onclick={() => resolve(r, 'resolved')}>Resolve</Button>
                                    <Button size="sm" variant="ghost" onclick={() => resolve(r, 'dismissed')}>Dismiss</Button>
                                {:else}
                                    <Badge variant={statusVariant(r.status)}>{r.status}</Badge>
                                {/if}
                            </TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="5" class="py-8 text-center text-muted-foreground">No reports.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={reports} />
        </CardContent>
    </Card>
</AdminLayout>
