<script>
    import { onMount } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Button, Input, Badge } from '$lib/components/ui';
    import { Copy, Search } from '@lucide/svelte';

    let { protocol } = $props();
    const id = $props.id();
    let dialog;

    let query = $state('');
    let users = $state([]);
    let searching = $state(false);
    let user = $state(null);
    let horses = $state([]);
    let loadingHorses = $state(false);
    let horseId = $state('');
    let destination = $state('new');
    let includeAnalysis = $state(false);
    let pending = $state(false);
    let error = $state('');
    let errors = $state({});
    let searchTimer;
    let searchRequest;
    let horsesRequest;

    const horse = $derived(horses.find((item) => item.id === horseId) ?? null);
    const drafts = $derived(horse?.drafts ?? []);
    const canSubmit = $derived(Boolean(user && horse) && !pending && !loadingHorses);

    function reset() {
        clearTimeout(searchTimer);
        searchRequest?.abort();
        horsesRequest?.abort();
        query = '';
        users = [];
        searching = false;
        user = null;
        horses = [];
        loadingHorses = false;
        horseId = '';
        destination = 'new';
        includeAnalysis = false;
        error = '';
        errors = {};
    }
    function open() {
        reset();
        dialog.showModal();
    }
    function close() {
        if (pending) return;
        reset();
        dialog?.close();
    }

    async function fetchJson(url, request) {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal });
        if (!response.ok) throw new Error(`Request failed: ${response.status}`);
        return response.json();
    }

    function search() {
        clearTimeout(searchTimer);
        searchRequest?.abort();
        error = '';
        const term = query.trim();
        if (term.length < 2) {
            users = [];
            searching = false;
            return;
        }
        searching = true;
        searchTimer = setTimeout(async () => {
            const request = new AbortController();
            searchRequest = request;
            try {
                const data = await fetchJson(`/admin/protocols/copy-targets/users?q=${encodeURIComponent(term)}`, request);
                if (!request.signal.aborted) users = data.users ?? [];
            } catch (exception) {
                if (!request.signal.aborted && exception.name !== 'AbortError') error = 'Gebruikers konden niet worden gezocht. Probeer opnieuw.';
            } finally {
                if (searchRequest === request) searching = false;
            }
        }, 250);
    }

    async function chooseUser(choice) {
        horsesRequest?.abort();
        user = choice;
        horses = [];
        horseId = '';
        destination = 'new';
        error = '';
        errors = {};
        loadingHorses = true;
        const request = new AbortController();
        horsesRequest = request;
        try {
            const data = await fetchJson(`/admin/protocols/copy-targets/users/${choice.id}/horses`, request);
            if (!request.signal.aborted) horses = data.horses ?? [];
        } catch (exception) {
            if (!request.signal.aborted && exception.name !== 'AbortError') error = 'De paarden van deze gebruiker konden niet worden geladen. Probeer opnieuw.';
        } finally {
            if (horsesRequest === request) loadingHorses = false;
        }
    }

    function changeUser() {
        horsesRequest?.abort();
        user = null;
        horses = [];
        horseId = '';
        destination = 'new';
        loadingHorses = false;
        errors = {};
    }

    function chooseHorse(choice) {
        horseId = choice.id;
        // Default to filling the horse's existing draft: that is where its intake already lives.
        destination = choice.drafts?.length ? choice.drafts[0].id : 'new';
        errors = {};
    }

    function horseUnavailable(item) {
        if (item.id === protocol.horse_id) return 'Paard van dit protocol';
        if (item.status !== 'active') return 'Gearchiveerd';
        return null;
    }

    function submit() {
        if (!canSubmit) return;
        pending = true;
        error = '';
        errors = {};
        const draft = destination !== 'new';
        router.post(`/admin/protocols/${protocol.id}/copy`, {
            user_id: user.id,
            horse_id: horse.id,
            destination: draft ? 'draft' : 'new',
            draft_id: draft ? destination : null,
            include_analysis: includeAnalysis,
        }, {
            preserveScroll: true,
            onSuccess: () => { pending = false; close(); },
            onError: (bag) => {
                errors = bag;
                error = bag.copy || bag.protocol || bag.destination || '';
            },
            onFinish: () => { pending = false; },
        });
    }

    onMount(() => {
        const handleFailure = (event) => {
            if (!pending) return;
            event.preventDefault();
            error = 'Het kopiëren kon niet worden bevestigd. Controleer de verbinding en probeer opnieuw.';
            pending = false;
        };
        const removeInvalid = router.on('invalid', handleFailure);
        const removeException = router.on('exception', handleFailure);
        return () => { clearTimeout(searchTimer); searchRequest?.abort(); horsesRequest?.abort(); removeInvalid(); removeException(); };
    });
</script>

<Button variant="outline" onclick={open}><Copy class="size-4" /> Kopiëren naar ander paard</Button>

<dialog bind:this={dialog} aria-labelledby={`${id}-title`} oncancel={(event) => { event.preventDefault(); close(); }}
    class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-xl border bg-card p-0 text-card-foreground shadow-xl backdrop:bg-black/50">
    <div class="border-b px-6 py-4">
        <h2 id={`${id}-title`} class="text-lg font-semibold">Protocol kopiëren naar ander paard</h2>
        <p class="mt-1 text-sm text-muted-foreground">{protocol.title} · {protocol.horse?.name}</p>
    </div>

    <div class="space-y-5 px-6 py-5 text-sm">
        <section class="space-y-2">
            <h3 class="font-medium">1. Gebruiker</h3>
            {#if user}
                <div class="flex items-center justify-between gap-3 rounded-lg border p-3">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{user.name}</p>
                        <p class="truncate text-muted-foreground">{user.email}</p>
                    </div>
                    <Button size="sm" variant="ghost" disabled={pending} onclick={changeUser}>Wijzigen</Button>
                </div>
            {:else}
                <label class="sr-only" for={`${id}-search`}>Zoek gebruiker op naam of e-mail</label>
                <div class="relative">
                    <Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                    <Input id={`${id}-search`} class="pl-8" placeholder="Zoek op naam of e-mail" autocomplete="off" bind:value={query} oninput={search} />
                </div>
                {#if searching}
                    <p role="status" class="text-muted-foreground">Zoeken…</p>
                {:else if query.trim().length >= 2 && users.length === 0 && !error}
                    <p class="text-muted-foreground">Geen gebruikers gevonden.</p>
                {/if}
                {#if users.length}
                    <ul class="max-h-56 divide-y overflow-y-auto rounded-lg border">
                        {#each users as item (item.id)}
                            <li>
                                <button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-muted/50" onclick={() => chooseUser(item)}>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium">{item.name}</span>
                                        <span class="block truncate text-muted-foreground">{item.email}</span>
                                    </span>
                                    <span class="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                                        {#if item.disabled}<Badge variant="muted">Geblokkeerd</Badge>{/if}
                                        {item.active_horses_count} {item.active_horses_count === 1 ? 'paard' : 'paarden'}
                                    </span>
                                </button>
                            </li>
                        {/each}
                    </ul>
                {/if}
            {/if}
            {#if errors.user_id}<p class="text-destructive">{errors.user_id}</p>{/if}
        </section>

        {#if user}
            <section class="space-y-2">
                <h3 class="font-medium">2. Paard van {user.name}</h3>
                {#if loadingHorses}
                    <p role="status" class="text-muted-foreground">Paarden laden…</p>
                {:else if horses.length === 0 && !error}
                    <p class="text-muted-foreground">Deze gebruiker heeft geen paarden.</p>
                {:else}
                    <div class="space-y-2" role="radiogroup" aria-label="Paard">
                        {#each horses as item (item.id)}
                            {@const unavailable = horseUnavailable(item)}
                            <label class={`flex items-center gap-3 rounded-lg border p-3 ${unavailable ? 'opacity-60' : 'cursor-pointer hover:bg-muted/40'} ${horseId === item.id ? 'border-primary bg-primary/5' : ''}`}>
                                <input type="radio" name={`${id}-horse`} value={item.id} checked={horseId === item.id} disabled={Boolean(unavailable) || pending} onchange={() => chooseHorse(item)} />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium">{item.name}</span>
                                    {#if item.breed}<span class="block text-muted-foreground">{item.breed}</span>{/if}
                                </span>
                                {#if unavailable}<Badge variant="muted">{unavailable}</Badge>
                                {:else if item.has_active_protocol}<Badge variant="muted">Heeft actief protocol</Badge>{/if}
                            </label>
                        {/each}
                    </div>
                {/if}
                {#if errors.horse_id}<p class="text-destructive">{errors.horse_id}</p>{/if}
            </section>
        {/if}

        {#if horse}
            <section class="space-y-2">
                <h3 class="font-medium">3. Bestemming</h3>
                <div class="space-y-2" role="radiogroup" aria-label="Bestemming">
                    {#each drafts as draft (draft.id)}
                        <label class={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 hover:bg-muted/40 ${destination === draft.id ? 'border-primary bg-primary/5' : ''}`}>
                            <input class="mt-1" type="radio" name={`${id}-destination`} value={draft.id} bind:group={destination} disabled={pending} />
                            <span>
                                <span class="block font-medium">Bestaand concept invullen: {draft.title}</span>
                                <span class="block text-muted-foreground">Intake {draft.intake_submitted ? 'is verstuurd' : 'is nog niet verstuurd'} · aangemaakt {new Date(draft.created_at).toLocaleDateString('nl-NL')}</span>
                            </span>
                        </label>
                    {/each}
                    <label class={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 hover:bg-muted/40 ${destination === 'new' ? 'border-primary bg-primary/5' : ''}`}>
                        <input class="mt-1" type="radio" name={`${id}-destination`} value="new" bind:group={destination} disabled={pending} />
                        <span>
                            <span class="block font-medium">Nieuw concept</span>
                            <span class="block text-muted-foreground">Maakt een nieuw protocol met een eigen, lege intake voor {user.name}.</span>
                        </span>
                    </label>
                </div>
                {#if errors.draft_id}<p class="text-destructive">{errors.draft_id}</p>{/if}

                <label class="flex cursor-pointer items-start gap-3 pt-2">
                    <input class="mt-1" type="checkbox" bind:checked={includeAnalysis} disabled={pending} />
                    <span>
                        <span class="block font-medium">Analyse meekopiëren</span>
                        <span class="block text-muted-foreground">De analyse beschrijft het oorspronkelijke paard. Kopieer alleen als je deze gaat aanpassen.</span>
                    </span>
                </label>
            </section>

            <div class="space-y-2 rounded-lg border bg-muted/30 p-3 text-muted-foreground">
                <p><span class="font-medium text-foreground">Wordt gekopieerd:</span> template, fases en weken, supplementen, gekozen en handmatige adviezen en instellingen{includeAnalysis ? ', analyse' : ''}.</p>
                <p><span class="font-medium text-foreground">Niet gekopieerd:</span> intake-antwoorden, gewicht en voerkeuzes van het paard, startdatum, voortgang en registraties.</p>
                <p>Automatische doseringen worden opnieuw berekend met het gewicht van {horse.name}. Handmatige adviezen en instructies kunnen nog tekst over het oorspronkelijke paard bevatten.</p>
                <p>De kopie blijft een ongepubliceerd concept. Aanpassen, publiceren en activeren kan pas nadat de intake bij dit protocol is verstuurd.</p>
            </div>
        {/if}

        {#if error}<p role="alert" class="rounded-lg bg-destructive/10 p-3 text-destructive">{error}</p>{/if}
    </div>

    <div class="flex justify-end gap-2 border-t px-6 py-4">
        <Button variant="outline" disabled={pending} onclick={close}>Annuleren</Button>
        <Button disabled={!canSubmit} onclick={submit}>{pending ? 'Bezig…' : 'Protocol kopiëren'}</Button>
    </div>
</dialog>
