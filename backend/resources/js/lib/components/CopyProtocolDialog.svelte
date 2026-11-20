<script>
    import { onMount } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Button, Input, Badge } from '$lib/components/ui';
    import { Copy } from '@lucide/svelte';

    // mode "same": a copy for the protocol's own horse. mode "test": a copy for a horse of one of the test users.
    let { protocol, mode = 'same', showTrigger = true } = $props();
    const id = $props.id();
    let dialog;

    const toTest = $derived(mode === 'test');
    const label = $derived(toTest ? 'Kopiëren naar testgebruiker' : 'Kopiëren');

    let horses = $state([]);
    // The source intake as it is when the dialog opens: { submitted, user }.
    let intake = $state(null);
    let loadingHorses = $state(false);
    let loadFailed = $state(false);
    let horseId = $state('');
    let destination = $state('new');
    let includeAnalysis = $state(false);
    let title = $state('');
    let pending = $state(false);
    let error = $state('');
    let errors = $state({});
    let horsesRequest;

    const horse = $derived(horses.find((item) => item.id === horseId) ?? null);
    const user = $derived(horse?.owner ?? null);
    const drafts = $derived(horse?.drafts ?? []);
    const sameHorse = $derived(horse?.id === protocol.horse_id);
    const chosenDraft = $derived(drafts.find((draft) => draft.id === destination) ?? null);
    // The intake belongs to its own user, who stays the previous owner after a horse transfer.
    const intakeUser = $derived(intake?.user ?? null);
    const sourceSubmitted = $derived(Boolean(intake?.submitted));
    // A cancelled intake cannot be used by a new copy; an existing draft has its own intake.
    const sourceCancelled = $derived(Boolean(intake?.cancelled));
    const otherOwner = $derived(Boolean(user && user.id !== intakeUser?.id));
    // A new draft uses the same intake as this protocol; nothing is copied. An existing draft keeps its own intake.
    // Intake answers such as the weight only count once the intake is submitted, and so does editing the copy.
    const intakeSubmitted = $derived(chosenDraft ? Boolean(chosenDraft.intake_submitted) : sourceSubmitted);
    // A reused draft gets the new title, so its own current title doesn't count as a clash.
    const titleClash = $derived(Boolean(title.trim()) && (horse?.protocols ?? []).some((item) => item.id !== chosenDraft?.id
        && item.title?.trim().toLocaleLowerCase('nl') === title.trim().toLocaleLowerCase('nl')));
    const canSubmit = $derived(Boolean(horse && destination && title.trim()) && !(destination === 'new' && sourceCancelled) && !pending && !loadingHorses);
    // The horse is a step of its own only when there is a choice.
    const step = $derived(toTest ? 1 : 0);

    function reset() {
        horsesRequest?.abort();
        horses = [];
        intake = null;
        loadingHorses = false;
        loadFailed = false;
        horseId = '';
        destination = 'new';
        includeAnalysis = false;
        title = protocol.title ?? '';
        error = '';
        errors = {};
    }
    export function open() {
        reset();
        dialog.showModal();
        loadHorses();
    }
    function close() {
        if (pending) return;
        reset();
        dialog?.close();
    }

    async function loadHorses() {
        horsesRequest?.abort();
        loadingHorses = true;
        loadFailed = false;
        const chosenOwner = user?.id;
        const request = new AbortController();
        horsesRequest = request;
        try {
            const response = await fetch(`/admin/protocols/${protocol.id}/copy-targets?to=${toTest ? 'test' : 'same'}`, { headers: { Accept: 'application/json' }, signal: request.signal });
            if (!response.ok) throw new Error(`Request failed: ${response.status}`);
            const data = await response.json();
            if (request.signal.aborted) return;
            horses = data.horses ?? [];
            intake = data.intake ?? null;
            // After a reload keep the admin's choice only where it still exists, with the same owner; never pick
            // another destination or recipient for them.
            const current = horses.find((item) => item.id === horseId && item.owner.id === chosenOwner && !horseUnavailable(item));
            if (!current) {
                horseId = '';
                destination = 'new';
                // The protocol's own horse is the only target of a plain copy: nothing to choose.
                if (!toTest && horses[0] && !horseUnavailable(horses[0])) chooseHorse(horses[0]);
            } else if (destination !== 'new' && !current.drafts?.some((draft) => draft.id === destination)) {
                destination = '';
            }
        } catch (exception) {
            if (request.signal.aborted || exception.name === 'AbortError') return;
            // Nothing can be copied from a list that may be out of date.
            horses = [];
            intake = null;
            horseId = '';
            destination = 'new';
            loadFailed = true;
        } finally {
            if (horsesRequest === request) loadingHorses = false;
        }
    }

    function chooseHorse(choice) {
        horseId = choice.id;
        // Always a new draft by default: filling an existing draft is a choice the admin makes.
        destination = 'new';
        errors = {};
    }

    function horseUnavailable(item) {
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
            title: title.trim(),
        }, {
            preserveScroll: true,
            onSuccess: () => { pending = false; close(); },
            onError: (bag) => {
                errors = bag;
                error = bag.copy || bag.protocol || bag.destination || bag.user_id || '';
                // The chosen horse or draft changed or is gone: show the current ones before the admin chooses again.
                if (bag.draft_id || bag.horse_id) loadHorses();
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
        return () => { horsesRequest?.abort(); removeInvalid(); removeException(); };
    });
</script>

{#if showTrigger}<Button variant="outline" onclick={open}><Copy class="size-4" /> {label}</Button>{/if}

<dialog bind:this={dialog} aria-labelledby={`${id}-title`} oncancel={(event) => { event.preventDefault(); close(); }}
    class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-xl border bg-card p-0 text-card-foreground shadow-xl backdrop:bg-black/50">
    <div class="border-b px-6 py-4">
        <h2 id={`${id}-title`} class="text-lg font-semibold">{toTest ? 'Protocol kopiëren naar testgebruiker' : 'Protocol kopiëren'}</h2>
        <p class="mt-1 text-sm text-muted-foreground">{protocol.title} · {protocol.horse?.name}</p>
    </div>

    <div class="space-y-5 px-6 py-5 text-sm">
        <section class="space-y-2">
            {#if toTest}<h3 class="font-medium">1. Paard van een testgebruiker</h3>{/if}
            {#if loadingHorses}
                <p role="status" class="text-muted-foreground">Laden…</p>
            {:else if loadFailed}
                <p role="alert" class="rounded-lg bg-destructive/10 p-3 text-destructive">De gegevens voor het kopiëren konden niet worden geladen.</p>
                <Button size="sm" variant="outline" onclick={loadHorses}>Opnieuw laden</Button>
            {:else if toTest}
                {#if horses.length === 0}
                    <p class="text-muted-foreground">Er zijn nog geen paarden van testgebruikers. Een admin markeert een gebruiker als testgebruiker op de pagina van die gebruiker (Users → Mark as test user); daarna verschijnen de actieve paarden van die gebruiker hier.</p>
                {:else}
                    <div class="max-h-72 space-y-2 overflow-y-auto" role="radiogroup" aria-label="Paard">
                        {#each horses as item (item.id)}
                            <label class={`flex cursor-pointer items-center gap-3 rounded-lg border p-3 hover:bg-muted/40 ${horseId === item.id ? 'border-primary bg-primary/5' : ''}`}>
                                <input type="radio" name={`${id}-horse`} value={item.id} checked={horseId === item.id} disabled={pending} onchange={() => chooseHorse(item)} />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium">{item.name}</span>
                                    <span class="block truncate text-muted-foreground">{item.owner.name} · {item.owner.email}{item.breed ? ` · ${item.breed}` : ''}</span>
                                </span>
                                {#if item.id === protocol.horse_id}<Badge variant="muted">Paard van dit protocol</Badge>{/if}
                                {#if item.has_active_protocol}<Badge variant="muted">Heeft actief protocol</Badge>{/if}
                            </label>
                        {/each}
                    </div>
                {/if}
            {:else if horses[0] && horseUnavailable(horses[0])}
                <p class="text-muted-foreground">{horses[0].name} is gearchiveerd en kan geen protocol krijgen. Herstel het paard eerst.</p>
            {:else if horse}
                <p class="text-muted-foreground">De kopie komt bij {horse.name} ({user.name}), naast dit protocol.</p>
            {/if}
            {#if errors.horse_id}<p class="text-destructive">{errors.horse_id}</p>{/if}
        </section>

        {#if horse}
            <section class="space-y-2">
                <h3 class="font-medium">{step + 1}. Bestemming</h3>
                <div class="space-y-2" role="radiogroup" aria-label="Bestemming">
                    {#each drafts as draft (draft.id)}
                        <label class={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 hover:bg-muted/40 ${destination === draft.id ? 'border-primary bg-primary/5' : ''}`}>
                            <input class="mt-1" type="radio" name={`${id}-destination`} value={draft.id} bind:group={destination} disabled={pending} />
                            <span>
                                <span class="block font-medium">Bestaand concept invullen: {draft.title}</span>
                                <span class="block text-muted-foreground">Houdt zijn intake ({draft.intake_submitted ? 'verstuurd' : 'nog niet verstuurd'}) · aangemaakt {new Date(draft.created_at).toLocaleDateString('nl-NL')}</span>
                            </span>
                        </label>
                    {/each}
                    <label class={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 hover:bg-muted/40 ${destination === 'new' ? 'border-primary bg-primary/5' : ''}`}>
                        <input class="mt-1" type="radio" name={`${id}-destination`} value="new" bind:group={destination} disabled={pending} />
                        <span>
                            <span class="block font-medium">Nieuw concept</span>
                            <span class="block text-muted-foreground">
                                {#if sourceCancelled}
                                    Niet mogelijk: de intake van dit protocol is geannuleerd, dus een nieuw protocol kan die niet gebruiken. Zet de intake eerst terug.
                                {:else}
                                    Maakt een nieuw protocol dat dezelfde intake gebruikt als dit protocol{sourceSubmitted ? '' : ' (nog niet verstuurd)'}. De intake wordt niet gekopieerd.
                                {/if}
                            </span>
                        </span>
                    </label>
                </div>
                {#if errors.draft_id}<p class="text-destructive">{errors.draft_id}</p>{/if}

                <label class="flex cursor-pointer items-start gap-3 pt-2">
                    <input class="mt-1" type="checkbox" bind:checked={includeAnalysis} disabled={pending} />
                    <span>
                        <span class="block font-medium">Analyse meekopiëren</span>
                        <span class="block text-muted-foreground">{sameHorse ? 'De analyse beschrijft dit paard zoals het was bij het oorspronkelijke protocol.' : 'De analyse beschrijft het oorspronkelijke paard. Kopieer alleen als je deze gaat aanpassen.'}</span>
                    </span>
                </label>
            </section>

            <section class="space-y-2">
                <h3 class="font-medium"><label for={`${id}-title-input`}>{step + 2}. Titel van de kopie</label></h3>
                <Input id={`${id}-title-input`} maxlength="255" autocomplete="off" bind:value={title} disabled={pending}
                    aria-invalid={Boolean(errors.title) || !title.trim()} aria-describedby={`${id}-title-hint`} />
                <p id={`${id}-title-hint`} class={titleClash ? 'text-amber-800 dark:text-amber-200' : 'text-muted-foreground'}>
                    {#if !title.trim()}
                        Vul een titel in.
                    {:else if titleClash}
                        {horse.name} heeft al een protocol met deze titel. {user.name} krijgt de titel in de app te zien; kies een titel waarmee de protocollen uit elkaar te houden zijn.
                    {:else}
                        {user.name} krijgt deze titel in de app te zien.
                    {/if}
                </p>
                {#if errors.title}<p class="text-destructive">{errors.title}</p>{/if}
            </section>

            {#if destination && !(destination === 'new' && sourceCancelled)}
            <div class="space-y-2 rounded-lg border bg-muted/30 p-3 text-muted-foreground">
                <p><span class="font-medium text-foreground">Wordt gekopieerd:</span> template, fases en weken, supplementen, gekozen en handmatige adviezen en instellingen{includeAnalysis ? ', analyse' : ''}.</p>
                <p><span class="font-medium text-foreground">Niet gekopieerd:</span> intake, gewicht- en voerinstellingen van het protocol, startdatum, voortgang en registraties.</p>
                <p>
                    {#if chosenDraft}
                        Automatische doseringen worden opnieuw berekend met het gewicht dat bij het concept is ingesteld, anders het gewicht uit de verstuurde intake van het concept, en anders het gewicht van {horse.name}.
                    {:else if sourceSubmitted}
                        Automatische doseringen worden opnieuw berekend met het gewicht uit de intake, of anders het gewicht van {horse.name}.
                    {:else}
                        Automatische doseringen worden opnieuw berekend met het gewicht van {horse.name}. Het gewicht uit de intake telt pas mee als de intake is verstuurd.
                    {/if}
                    {#if !sameHorse}Handmatige adviezen en instructies kunnen nog gegevens over het oorspronkelijke paard bevatten{chosenDraft ? '' : ', en voeradviezen blijven gebaseerd op de intake van dat paard'}.{/if}
                </p>
                <p>
                    De kopie blijft een ongepubliceerd concept.
                    {#if intakeSubmitted}
                        De intake is al verstuurd, dus je kunt de kopie direct aanpassen en publiceren.
                        {#if horse.has_active_protocol}
                            Activeren kan pas als het actieve protocol van {horse.name} is afgerond of gepauzeerd.
                        {:else}
                            Activeren kan daarna ook meteen.
                        {/if}
                    {:else}
                        Aanpassen, publiceren en activeren kan zodra {chosenDraft ? `${user.name} de intake van het concept` : `${intakeUser?.name ?? 'de klant'} de intake`} heeft verstuurd.
                    {/if}
                </p>
            </div>
            {#if !chosenDraft && otherOwner}
                <p role="note" class="rounded-lg border bg-muted/30 p-3 text-muted-foreground">
                    De intake blijft van {intakeUser?.name ?? 'de klant'}: {user.name} ziet het protocol in de app, maar niet de antwoorden en foto’s uit de intake.
                </p>
            {/if}
            {/if}
        {/if}

        {#if error}<p role="alert" class="rounded-lg bg-destructive/10 p-3 text-destructive">{error}</p>{/if}
    </div>

    <div class="flex justify-end gap-2 border-t px-6 py-4">
        <Button variant="outline" disabled={pending} onclick={close}>Annuleren</Button>
        <Button disabled={!canSubmit} onclick={submit}>{pending ? 'Bezig…' : 'Protocol kopiëren'}</Button>
    </div>
</dialog>
