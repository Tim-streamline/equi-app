<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import StatCard from '$lib/components/StatCard.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardContent, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { formatCents, formatDate } from '$lib/utils.js';
    import { statusVariant } from '$lib/badges.js';
    import { CreditCard } from '@lucide/svelte';

    let { subscriptions, filters, plans, mrr_cents } = $props();
    let status = $state(filters.status ?? '');
    let plan = $state(filters.plan ?? '');
    function apply() { router.get('/admin/subscriptions', { status, plan }, { preserveState: true, replace: true }); }
</script>

<AdminLayout title="Subscriptions">
    <PageHeader title="Subscriptions" description={`${subscriptions.total} subscriptions`} />
    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <StatCard label="Monthly recurring revenue" value={formatCents(mrr_cents)} icon={CreditCard} accent="success" />
    </div>
    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <Select class="w-44" bind:value={status} onchange={apply} options={[
                    { value: '', label: 'All statuses' }, { value: 'active', label: 'Active' },
                    { value: 'cancelled', label: 'Cancelled' }, { value: 'past_due', label: 'Past due' }]} />
                <Select class="w-44" bind:value={plan} onchange={apply} options={[{ value: '', label: 'All plans' }, ...plans.map((p) => ({ value: p.id, label: p.name }))]} />
            </div>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>User</TableHead>
                        <TableHead>Plan</TableHead>
                        <TableHead>Price</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Renews</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {#each subscriptions.data as s (s.id)}
                        <TableRow class="cursor-pointer" onclick={() => router.visit(`/admin/subscriptions/${s.id}`)}>
                            <TableCell><div class="font-medium">{s.user?.name}</div><div class="text-xs text-muted-foreground">{s.user?.email}</div></TableCell>
                            <TableCell>{s.plan?.name}</TableCell>
                            <TableCell>{formatCents(s.price_cents, s.currency)}</TableCell>
                            <TableCell><Badge variant={statusVariant(s.status)}>{s.status}</Badge></TableCell>
                            <TableCell class="text-muted-foreground">{formatDate(s.renews_at)}</TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="5" class="py-8 text-center text-muted-foreground">No subscriptions.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={subscriptions} />
        </CardContent>
    </Card>
</AdminLayout>
