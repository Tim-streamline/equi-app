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

    let { booking, review, protocols = [], printMode = false, pdfFallback = false } = $props();
    let tab = $state('intake');
    let reviewComponent;
    async function exportPdf() {
        const target = window.open('', '_blank');
        if (!reviewComponent || await reviewComponent.flush()) {
            const url = `/admin/bookings/${booking.id}/answers.pdf`;
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
    const mailStatus = {
        pending: 'In afwachting', sending: 'Verzending gestart',
        sent: 'Verzonden', failed: 'Mislukt, controleer de verzending',
    };
    function setStatus(status) { router.post(`/admin/bookings/${booking.id}/status`, { status }); }
    // A second protocol on the same intake, for example a follow-up, without asking the customer for a new intake.
    const canAddProtocol = $derived(Boolean(booking.horse?.status === 'active' && booking.submitted_at && booking.status !== 'cancelled'));
    let addingProtocol = $state(false);
    let protocolError = $state('');
    function addProtocol() {
        if (!window.confirm('Een nieuw, leeg concept aanmaken dat deze intake gebruikt? De bestaande protocollen op deze intake blijven zoals ze zijn. De klant ziet het nieuwe protocol pas als het is gepubliceerd en actief is.')) return;
        addingProtocol = true;
        protocolError = '';
        router.post(`/admin/bookings/${booking.id}/protocols`, {}, {
            onError: (errors) => { protocolError = Object.values(errors).join(' '); },
            onFinish: () => { addingProtocol = false; },
        });
    }
    const protocolStatus = (protocol) => !protocol.configured ? (protocol.status === 'draft' ? 'leeg concept' : `leeg, ${protocol.status}`) : `${protocol.status}${protocol.published ? '' : ', niet gepubliceerd'}`;
</script>

{#if printMode}
    <main class="mx-auto max-w-5xl p-8 print:p-0">
        {#if pdfFallback}
            <div role="status" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm print:hidden">
                De automatische PDF-export is niet gelukt. Je kunt deze volledige intake alsnog opslaan via het afdrukvenster: kies ‘Opslaan als PDF’. Controleer of alle foto’s zichtbaar zijn voordat je opslaat.
            </div>
        {/if}
        <h1 class="mb-2 text-2xl font-semibold">Intake · {booking.horse?.name || 'Paard niet gekoppeld'}</h1>
        <p class="mb-6">{booking.user?.name} · {`Ingediend op: ${booking.submitted_at_label}`} · {booking.id}</p>
        <a href={`/admin/bookings/${booking.id}`} class="mb-4 mr-6 inline-block text-primary underline print:hidden">Terug naar intake</a>
        <button class="mb-4 text-primary underline print:hidden" onclick={() => window.print()}>Afdrukken / opslaan als PDF</button>
        <IntakeReview {booking} {review} printMode={true}/>
    </main>
{:else}
<AdminLayout title="Intake bookings">
    <Link href="/admin/bookings" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Terug naar intake bookings
    </Link>
    <PageHeader title={`Intake · ${booking.horse?.name || 'Paard niet gekoppeld'}`} description={`${booking.user?.name} · Ingediend op: ${booking.submitted_at_label} · ${booking.id}`}>
        {#snippet actions()}
            <Badge>{booking.submitted_at ? 'Intake ingevuld' : 'Intake in concept'}</Badge>
            <Badge variant={statusVariant(booking.status)}>{booking.status_label}</Badge>
            <Button variant="outline" onclick={exportPdf}>Exporteer PDF</Button>
            {#if canAddProtocol}<Button variant="outline" disabled={addingProtocol} onclick={addProtocol}>Nieuw protocol op deze intake</Button>{/if}
            {#if booking.horse_id}<Link href={`/admin/protocols/${booking.protocol_id}/edit`} class="inline-flex h-11 items-center rounded-full bg-primary px-5 font-semibold text-white">Protocol opstellen</Link>{/if}
        {/snippet}
    </PageHeader>
    {#if protocolError}<p role="alert" class="mb-4 rounded-md border border-destructive/30 bg-destructive/10 px-4 py-2 text-sm text-destructive">{protocolError}</p>{/if}
    {#if protocols.length > 1}
        <div class="mb-4 rounded-md border bg-muted/30 px-4 py-3 text-sm">
            <p class="mb-1 font-medium">{protocols.length} protocollen gebruiken deze intake</p>
            <ul class="space-y-1">
                {#each protocols as protocol (protocol.id)}
                    <li>
                        <Link href={`/admin/protocols/${protocol.id}/edit`} class="text-primary underline">{protocol.title}</Link>
                        <span class="text-muted-foreground"> · {protocolStatus(protocol)}{protocol.horse ? ` · op ${protocol.horse}` : ''}{protocol.id === booking.protocol_id ? ' · “Protocol opstellen” opent dit protocol' : ''}</span>
                    </li>
                {/each}
            </ul>
        </div>
    {/if}
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
                <div class="flex justify-between gap-4"><span class="text-muted-foreground">Melding nieuwe intake</span><span>{mailStatus[booking.submission_email_status] ?? 'Niet aangevraagd'}</span></div>
                {#if booking.submission_email_sent_at}<p>Melding verzonden op {formatDateTime(booking.submission_email_sent_at)}</p>{/if}
                {#if booking.submission_email_error}<p class="text-destructive">{booking.submission_email_error}</p>{/if}
                {#if booking.notes}<p class="pt-2 text-muted-foreground">{booking.notes}</p>{/if}
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Update status</CardTitle></CardHeader>
            <CardContent class="flex flex-wrap gap-2">
                <Button variant="outline" onclick={() => setStatus('confirmed')}>Confirm</Button>
                <Button variant="outline" onclick={() => setStatus('done')}>Markeer als beoordeeld</Button>
                <Button variant="outline" onclick={() => setStatus('pending')}>Te beoordelen</Button>
                <Button variant="destructive" onclick={() => setStatus('cancelled')}>Cancel</Button>
            </CardContent>
        </Card>
    </div>
    </div>
</AdminLayout>
{/if}
