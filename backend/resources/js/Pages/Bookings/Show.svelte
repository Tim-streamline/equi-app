<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Badge } from '$lib/components/ui';
    import { formatDateTime } from '$lib/utils.js';
    import { statusVariant } from '$lib/badges.js';
    import { ArrowLeft } from '@lucide/svelte';

    let { booking } = $props();
    function setStatus(status) { router.post(`/admin/bookings/${booking.id}/status`, { status }); }
</script>

<AdminLayout title="Booking">
    <Link href="/admin/bookings" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Back
    </Link>
    <PageHeader title={`Intake · ${booking.therapist?.name}`} description={formatDateTime(booking.scheduled_at)}>
        {#snippet actions()}<Badge variant={statusVariant(booking.status)}>{booking.status}</Badge>{/snippet}
    </PageHeader>

    <div class="grid gap-4 lg:grid-cols-2">
        <Card>
            <CardHeader><CardTitle>Details</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-muted-foreground">User</span><span>{booking.user?.name}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Email</span><span>{booking.user?.email}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Horse</span><span>{booking.horse?.name ?? '—'}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Duration</span><span>{booking.duration_minutes} min</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Slot</span><span>{booking.slot_label ?? '—'}</span></div>
                {#if booking.notes}<p class="pt-2 text-muted-foreground">{booking.notes}</p>{/if}
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Update status</CardTitle></CardHeader>
            <CardContent class="flex flex-wrap gap-2">
                <Button variant="outline" onclick={() => setStatus('confirmed')}>Confirm</Button>
                <Button variant="outline" onclick={() => setStatus('done')}>Mark done</Button>
                <Button variant="outline" onclick={() => setStatus('pending')}>Set pending</Button>
                <Button variant="destructive" onclick={() => setStatus('cancelled')}>Cancel</Button>
            </CardContent>
        </Card>
    </div>
</AdminLayout>
