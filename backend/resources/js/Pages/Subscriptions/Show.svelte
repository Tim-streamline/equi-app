<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Field from '$lib/components/Field.svelte';
    import { Link, useForm } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { formatCents, formatDate } from '$lib/utils.js';
    import { statusVariant } from '$lib/badges.js';
    import { ArrowLeft } from '@lucide/svelte';

    let { subscription } = $props();
    const form = useForm({
        status: subscription.status,
        plan_id: subscription.plan_id,
        renews_at: subscription.renews_at ? subscription.renews_at.slice(0, 10) : '',
        max_horses: subscription.max_horses,
        reason: '',
    });
    function submit(e) { e.preventDefault(); $form.put(`/admin/subscriptions/${subscription.id}`); }
</script>

<AdminLayout title="Subscription">
    <Link href="/admin/subscriptions" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Back
    </Link>
    <PageHeader title={subscription.plan?.name} description={subscription.user?.name} />

    <div class="grid gap-4 lg:grid-cols-2">
        <Card>
            <CardHeader><CardTitle>Manage subscription</CardTitle></CardHeader>
            <CardContent>
                <form onsubmit={submit} class="space-y-4">
                    <Field label="Status" error={$form.errors.status}>
                        <Select bind:value={$form.status} options={[
                            { value: 'active', label: 'Active' }, { value: 'cancelled', label: 'Cancelled' }, { value: 'past_due', label: 'Past due' }]} />
                    </Field>
                    <Field label="Renews at" error={$form.errors.renews_at}><Input type="date" bind:value={$form.renews_at} /></Field>
                    <Field label="Max horses" error={$form.errors.max_horses}><Input type="number" bind:value={$form.max_horses} /></Field>
                    <Field label="Reason (audit)" error={$form.errors.reason}><Input bind:value={$form.reason} /></Field>
                    <Button type="submit" disabled={$form.processing}>Save</Button>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Payments</CardTitle></CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Date</TableHead><TableHead>Amount</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                    <TableBody>
                        {#each subscription.payments as p (p.id)}
                            <TableRow>
                                <TableCell>{formatDate(p.date)}</TableCell>
                                <TableCell>{formatCents(p.amount_cents, p.currency)}</TableCell>
                                <TableCell><Badge variant={statusVariant(p.status)}>{p.status}</Badge></TableCell>
                            </TableRow>
                        {:else}
                            <TableRow><TableCell colspan="3" class="py-6 text-center text-muted-foreground">No payments.</TableCell></TableRow>
                        {/each}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</AdminLayout>
