<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import StatCard from '$lib/components/StatCard.svelte';
    import { router } from '@inertiajs/svelte';
    import { Card, CardContent, Select, Badge, Button, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { formatCents, formatDate } from '$lib/utils.js';
    import { statusVariant } from '$lib/badges.js';
    import { CheckCircle2, XCircle, Undo2, ExternalLink } from '@lucide/svelte';

    let { payments, filters, totals } = $props();
    let status = $state(filters.status ?? '');
    function apply() { router.get('/admin/payments', { status }, { preserveState: true, replace: true }); }
</script>

<AdminLayout title="Payments">
    <PageHeader title="Payments" description="Receipts, failures and refunds" />
    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <StatCard label="Collected" value={formatCents(totals.paid)} icon={CheckCircle2} accent="success" />
        <StatCard label="Failed payments" value={totals.failed} icon={XCircle} accent="destructive" />
        <StatCard label="Refunded" value={formatCents(totals.refunded)} icon={Undo2} accent="warning" />
    </div>
    <Card>
        <CardContent class="p-4">
            <div class="mb-4">
                <Select class="w-44" bind:value={status} onchange={apply} options={[
                    { value: '', label: 'All' }, { value: 'paid', label: 'Paid' }, { value: 'failed', label: 'Failed' }, { value: 'refunded', label: 'Refunded' }]} />
            </div>
            <Table>
                <TableHeader><TableRow><TableHead>Date</TableHead><TableHead>User</TableHead><TableHead>Amount</TableHead><TableHead>Status</TableHead><TableHead>Receipt</TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each payments.data as p (p.id)}
                        <TableRow>
                            <TableCell>{formatDate(p.date)}</TableCell>
                            <TableCell>{p.user?.name ?? '—'}</TableCell>
                            <TableCell>{formatCents(p.amount_cents, p.currency)}</TableCell>
                            <TableCell><Badge variant={statusVariant(p.status)}>{p.status}</Badge></TableCell>
                            <TableCell>{#if p.receipt_url}<a href={p.receipt_url} target="_blank" class="inline-flex items-center gap-1 text-primary"><ExternalLink class="size-4" /> View</a>{:else}—{/if}</TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="5" class="py-8 text-center text-muted-foreground">No payments.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={payments} />
        </CardContent>
    </Card>
</AdminLayout>
