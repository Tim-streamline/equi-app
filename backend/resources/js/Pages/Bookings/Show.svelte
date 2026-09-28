<script>
    import IntakeReview from './components/IntakeReview.svelte';
    import { onMount } from 'svelte';
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import BookingDeleteAction from '$lib/components/BookingDeleteAction.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Badge } from '$lib/components/ui';
    import { formatDateTime } from '$lib/utils.js';
    import { statusVariant } from '$lib/badges.js';
    import { ArrowLeft } from '@lucide/svelte';

    let { booking, review, printMode = false } = $props();
    let tab = $state('intake');
    let reviewComponent;
    async function exportPdf() {
        const target = window.open('', '_blank');
        if (!reviewComponent || await reviewComponent.flush()) {
            const url = `/admin/bookings/${booking.id}?print=1`;
            if (target) target.location.href = url;
            else window.location.assign(url);
        } else target?.close();
    }
    onMount(() => {
        if (printMode) void (async () => {
            await document.fonts.ready;
            await Promise.all([...document.images].map(img => img.complete ? Promise.resolve() : new Promise(resolve => { img.onload = resolve; img.onerror = resolve; })));
            window.print();
        })();
    });
    function setStatus(status) { router.post(`/admin/bookings/${booking.id}/status`, { status }); }
</script>

{#if printMode}
    <main class="mx-auto max-w-5xl p-8 print:p-0">
        <h1 class="mb-2 text-2xl font-semibold">Intake · {booking.horse?.name || 'Paard niet gekoppeld'}</h1>
        <p class="mb-6">{booking.user?.name} · {booking.submitted_at ? `Ingevuld op ${formatDateTime(booking.submitted_at)}` : 'Nog niet ingestuurd'} · {booking.id}</p>
        <a href={`/admin/bookings/${booking.id}`} class="mb-4 mr-6 inline-block text-primary underline print:hidden">Terug naar intake</a>
        <button class="mb-4 text-primary underline print:hidden" onclick={() => window.print()}>Afdrukken / opslaan als PDF</button>
        <IntakeReview {booking} {review} printMode={true}/>
    </main>
{:else}
<AdminLayout title="Intake bookings">
    <Link href="/admin/bookings" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Terug naar intake bookings
    </Link>
    <PageHeader title={`Intake · ${booking.horse?.name || 'Paard niet gekoppeld'}`} description={`${booking.user?.name} · ${booking.submitted_at ? `Ingevuld op ${formatDateTime(booking.submitted_at)}` : 'Nog niet ingestuurd'} · ${booking.id}`}>
        {#snippet actions()}
            <Badge>{booking.submitted_at ? 'Intake ingevuld' : 'Intake in concept'}</Badge>
            <Button variant="outline" onclick={exportPdf}>Exporteer PDF</Button>
            {#if booking.horse_id}<Link href={`/admin/protocols/create?horse_id=${booking.horse_id}`} class="inline-flex h-11 items-center rounded-full bg-primary px-5 font-semibold text-white">Protocol opstellen</Link>{/if}
        {/snippet}
    </PageHeader>
    <div role="tablist" aria-label="Booking" class="mb-6 flex gap-6 border-b">
        <button role="tab" aria-selected={tab === 'details'} class="border-b-2 px-1 py-3" class:border-primary={tab === 'details'} onclick={() => tab = 'details'}>Details</button>
        <button role="tab" aria-selected={tab === 'intake'} class="border-b-2 px-1 py-3" class:border-primary={tab === 'intake'} onclick={() => tab = 'intake'}>Intake-antwoorden</button>
        <button role="tab" disabled class="px-1 py-3 text-muted-foreground" title="Binnenkort beschikbaar">Protocol (later)</button>
    </div>
    <div hidden={tab !== 'intake'}><IntakeReview {booking} {review} bind:this={reviewComponent}/></div>
    <div hidden={tab !== 'details'}>
    <div class="mb-4"><BookingDeleteAction {booking}/></div>
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
    </div>
</AdminLayout>
{/if}
