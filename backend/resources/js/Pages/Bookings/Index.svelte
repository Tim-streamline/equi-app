<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import StatCard from '$lib/components/StatCard.svelte';
    import { router } from '@inertiajs/svelte';
    import { Card, CardContent, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { formatDateTime } from '$lib/utils.js';
    import { statusVariant } from '$lib/badges.js';
    import { CalendarClock, CalendarCheck } from '@lucide/svelte';

    let { bookings, filters, therapists, counts } = $props();
    let status = $state(filters.status ?? '');
    let therapist = $state(filters.therapist ?? '');
    function apply() { router.get('/admin/bookings', { status, therapist }, { preserveState: true, replace: true }); }
</script>

<AdminLayout title="Intake bookings">
    <PageHeader title="Intake bookings" description="Therapist intake calendar" />
    <div class="mb-4 grid gap-4 sm:grid-cols-2">
        <StatCard label="Pending" value={counts.pending} icon={CalendarClock} accent="warning" />
        <StatCard label="Confirmed" value={counts.confirmed} icon={CalendarCheck} accent="success" />
    </div>
    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <Select class="w-44" bind:value={status} onchange={apply} options={[
                    { value: '', label: 'All statuses' }, { value: 'pending', label: 'Pending' },
                    { value: 'confirmed', label: 'Confirmed' }, { value: 'done', label: 'Done' }, { value: 'cancelled', label: 'Cancelled' }]} />
                <Select class="w-48" bind:value={therapist} onchange={apply} options={[{ value: '', label: 'All therapists' }, ...therapists.map((t) => ({ value: t.id, label: t.name }))]} />
            </div>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>When</TableHead>
                        <TableHead>User</TableHead>
                        <TableHead>Horse</TableHead>
                        <TableHead>Therapist</TableHead>
                        <TableHead>Status</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {#each bookings.data as b (b.id)}
                        <TableRow class="cursor-pointer" onclick={() => router.visit(`/admin/bookings/${b.id}`)}>
                            <TableCell class="whitespace-nowrap">{formatDateTime(b.scheduled_at)}</TableCell>
                            <TableCell>{b.user?.name}</TableCell>
                            <TableCell class="text-muted-foreground">{b.horse?.name ?? '—'}</TableCell>
                            <TableCell>{b.therapist?.name}</TableCell>
                            <TableCell><Badge variant={statusVariant(b.status)}>{b.status}</Badge></TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="5" class="py-8 text-center text-muted-foreground">No bookings.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={bookings} />
        </CardContent>
    </Card>
</AdminLayout>
