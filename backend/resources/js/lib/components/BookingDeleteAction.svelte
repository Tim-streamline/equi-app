<script>
    import { onMount } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Button } from '$lib/components/ui';
    import { Trash2 } from '@lucide/svelte';
    import { formatDateTime } from '$lib/utils.js';

    let { booking } = $props();
    const id = $props.id();
    let dialog;
    let pending = $state(false);
    let error = $state('');

    function open() {
        error = '';
        dialog.showModal();
    }
    function remove() {
        if (pending) return;
        pending = true;
        error = '';
        router.delete(`/admin/bookings/${booking.id}`, {
            data: { confirm_delete: true },
            preserveScroll: true,
            onSuccess: () => dialog?.close(),
            onError: (errors) => { error = errors.booking_delete || errors.confirm_delete || 'De booking kon niet worden verwijderd. Probeer opnieuw.'; },
            onFinish: () => { pending = false; },
        });
    }
    onMount(() => {
        const handleFailure = (event) => {
            if (!pending) return;
            event.preventDefault();
            error = 'De verwijdering kon niet worden bevestigd. Controleer de verbinding en probeer opnieuw.';
            pending = false;
        };
        const removeInvalid = router.on('invalid', handleFailure);
        const removeException = router.on('exception', handleFailure);
        return () => { removeInvalid(); removeException(); };
    });
</script>

<Button size="sm" variant="destructive" aria-label={`Verwijderen booking ${booking.user?.name ?? ''} ${formatDateTime(booking.scheduled_at)}`} onclick={open}>
    <Trash2 class="size-4" /> Verwijderen
</Button>

<dialog bind:this={dialog} aria-labelledby={`${id}-title`} oncancel={(event) => { if (pending) event.preventDefault(); }}
    class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-xl border bg-card p-0 text-card-foreground shadow-xl backdrop:bg-black/50">
    <div class="border-b px-6 py-4">
        <h2 id={`${id}-title`} class="text-lg font-semibold">Booking verwijderen</h2>
        <p class="mt-1 text-sm text-muted-foreground">{booking.user?.name} · {formatDateTime(booking.scheduled_at)}</p>
        <p class="mt-1 text-sm text-muted-foreground">{booking.therapist?.name}{booking.horse?.name ? ` · ${booking.horse.name}` : ''}</p>
    </div>
    <div class="space-y-4 px-6 py-5 text-sm">
        <p>Weet je zeker dat je deze booking wilt verwijderen? Deze actie kan niet ongedaan worden gemaakt.</p>
        {#if error}<p role="alert" class="rounded-lg bg-destructive/10 p-3 text-destructive">{error}</p>{/if}
    </div>
    <div class="flex justify-end gap-2 border-t px-6 py-4">
        <Button variant="outline" disabled={pending} onclick={() => dialog.close()}>Annuleren</Button>
        <Button variant="destructive" disabled={pending} onclick={remove}>{pending ? 'Bezig…' : 'Definitief verwijderen'}</Button>
    </div>
</dialog>
