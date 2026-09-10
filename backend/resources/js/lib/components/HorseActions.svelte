<script>
    import { onMount } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Button } from '$lib/components/ui';
    import { Archive, RotateCcw, Trash2 } from '@lucide/svelte';

    let { horse } = $props();
    const id = $props.id();
    let dialog;
    let action = $state(null);
    let pending = $state(false);
    let loading = $state(false);
    let error = $state('');
    let preview = $state(null);
    let previewRequest;
    const deleting = $derived(action === 'delete');
    const restoring = $derived(action === 'restore');

    async function open(nextAction) {
        previewRequest?.abort();
        loading = false;
        action = nextAction;
        error = '';
        preview = null;
        dialog.showModal();
        if (nextAction !== 'delete') return;
        loading = true;
        const request = new AbortController();
        previewRequest = request;
        try {
            const response = await fetch(`/admin/horses/${horse.id}/deletion-preview`, {
                headers: { Accept: 'application/json' }, signal: request.signal,
            });
            if (!response.ok) throw new Error('Preview failed');
            const data = await response.json();
            if (!data.removed || !data.detached) throw new Error('Invalid preview');
            if (!request.signal.aborted) preview = data;
        } catch (exception) {
            if (!request.signal.aborted && exception.name !== 'AbortError') error = 'De gekoppelde gegevens konden niet worden gecontroleerd. Sluit dit venster en probeer opnieuw.';
        } finally {
            if (previewRequest === request) loading = false;
        }
    }
    function close() {
        previewRequest?.abort();
        dialog?.close();
    }
    function confirm() {
        if (pending || loading || (deleting && !preview)) return;
        pending = true;
        error = '';
        const options = {
            preserveScroll: true,
            onSuccess: close,
            onError: (errors) => { error = errors.horse_action || errors.confirm_delete || 'De actie is niet gelukt. Probeer opnieuw.'; },
            onFinish: () => { pending = false; },
        };
        if (deleting) router.delete(`/admin/horses/${horse.id}`, { ...options, data: { confirm_delete: true } });
        else router.post(`/admin/horses/${horse.id}/${action}`, {}, options);
    }
    onMount(() => {
        const handleFailure = (event) => {
            if (!pending) return;
            event.preventDefault();
            error = 'De actie kon niet worden bevestigd. Controleer de verbinding en probeer opnieuw.';
            pending = false;
        };
        const removeInvalid = router.on('invalid', handleFailure);
        const removeException = router.on('exception', handleFailure);
        return () => { previewRequest?.abort(); removeInvalid(); removeException(); };
    });
</script>

<div class="flex flex-wrap gap-2">
    {#if horse.status === 'archived'}
        <Button size="sm" variant="outline" aria-label={`Herstellen ${horse.name}`} onclick={() => open('restore')}><RotateCcw class="size-4" /> Herstellen</Button>
    {:else}
        <Button size="sm" variant="outline" aria-label={`Archiveren ${horse.name}`} onclick={() => open('archive')}><Archive class="size-4" /> Archiveren</Button>
    {/if}
    <Button size="sm" variant="destructive" aria-label={`Verwijderen ${horse.name}`} onclick={() => open('delete')}><Trash2 class="size-4" /> Verwijderen</Button>
</div>

<dialog bind:this={dialog} aria-labelledby={`${id}-title`} oncancel={(event) => { if (pending) event.preventDefault(); else previewRequest?.abort(); }}
    class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-xl border bg-card p-0 text-card-foreground shadow-xl backdrop:bg-black/50">
    <div class="border-b px-6 py-4">
        <h2 id={`${id}-title`} class="text-lg font-semibold">{deleting ? 'Paard definitief verwijderen' : restoring ? 'Paard herstellen' : 'Paard archiveren'}</h2>
        <p class="mt-1 text-sm text-muted-foreground">{horse.name}</p>
    </div>
    <div class="space-y-4 px-6 py-5 text-sm">
        {#if deleting}
            <p>Weet je zeker dat je dit paard definitief wilt verwijderen? Alle gekoppelde gegevens die bij dit paard horen kunnen hierdoor verloren gaan. Deze actie kan niet ongedaan worden gemaakt.</p>
            {#if loading}<p role="status" class="text-muted-foreground">Gekoppelde gegevens controleren…</p>{/if}
            {#if preview}
                <div class="rounded-lg border border-destructive/30 bg-destructive/5 p-3">
                    <p class="font-medium">Wordt definitief verwijderd</p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        <li>Het paard</li>
                        {#each Object.values(preview.removed).filter((item) => item.count > 0) as item}
                            <li>{item.label}: {item.count}</li>
                        {/each}
                    </ul>
                </div>
                <div class="rounded-lg border p-3">
                    <p class="font-medium">Blijft bewaard bij het gebruikersaccount</p>
                    <p class="mt-1 text-muted-foreground">Scans, bookings, intakes, chats en gegevensexports blijven behouden. De koppeling met dit paard wordt verwijderd.</p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        {#each Object.values(preview.detached).filter((item) => item.count > 0) as item}
                            <li>{item.label}: {item.count}</li>
                        {/each}
                    </ul>
                </div>
            {/if}
        {:else if restoring}
            <p>Wil je dit paard herstellen? Het paard wordt weer zichtbaar in het actieve Horses-overzicht. Alle gekoppelde gegevens blijven behouden.</p>
        {:else}
            <p>Weet je zeker dat je dit paard wilt archiveren? Alle gekoppelde gegevens blijven behouden. Je kunt het paard later terugvinden en herstellen via Gearchiveerd.</p>
        {/if}
        {#if error}<p role="alert" class="rounded-lg bg-destructive/10 p-3 text-destructive">{error}</p>{/if}
    </div>
    <div class="flex justify-end gap-2 border-t px-6 py-4">
        <Button variant="outline" disabled={pending} onclick={close}>Annuleren</Button>
        <Button variant={deleting ? 'destructive' : 'default'} disabled={pending || loading || (deleting && !preview)} onclick={confirm}>
            {pending ? 'Bezig…' : deleting ? 'Definitief verwijderen' : restoring ? 'Paard herstellen' : 'Paard archiveren'}
        </Button>
    </div>
</dialog>
