<script>
    import { Button } from '$lib/components/ui';
    let { open = $bindable(false), restoring = false, name = '', busy = false, error = '', onconfirm } = $props();
    let dialog;
    $effect(() => {
        if (open && !dialog.open) dialog.showModal();
        else if (!open && dialog.open) dialog.close();
    });
</script>

<dialog bind:this={dialog} aria-labelledby="archive-confirm-title" onclose={() => (open = false)}
    oncancel={(event) => { if (busy) event.preventDefault(); }}
    class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-xl border bg-card p-0 text-card-foreground shadow-xl backdrop:bg-black/50">
    <div class="border-b px-6 py-4">
        <h2 id="archive-confirm-title" class="text-lg font-semibold">{restoring ? 'Herstellen' : 'Archiveren'}: {name}</h2>
    </div>
    <div class="space-y-3 px-6 py-5 text-sm leading-6">
        {#if restoring}
            <p>Wil je deze therapist / author herstellen? Deze persoon is daarna weer beschikbaar voor nieuwe bookings en content.</p>
        {:else}
            <p>Weet je zeker dat je deze therapist / author wilt archiveren? Bestaande bookings en gekoppelde gegevens blijven behouden. Deze persoon is daarna niet meer beschikbaar voor nieuwe bookings of nieuwe koppelingen als author.</p>
        {/if}
        {#if error}<p role="alert" class="text-destructive">{error}</p>{/if}
    </div>
    <div class="flex justify-end gap-2 border-t px-6 py-4">
        <Button variant="outline" disabled={busy} onclick={() => (open = false)}>Annuleren</Button>
        <Button disabled={busy} onclick={onconfirm}>{busy ? 'Bezig…' : restoring ? 'Herstellen' : 'Archiveren'}</Button>
    </div>
</dialog>
