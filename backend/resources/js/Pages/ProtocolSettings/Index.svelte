<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Badge, Button, Input, Select, Textarea } from '$lib/components/ui';
    import { CalendarDays, Check, ChevronRight, Layers3, Pencil, Plus, Trash2, X } from '@lucide/svelte';
    import { fade } from 'svelte/transition';

    let { protocolTypes } = $props();

    let selectedTypeId = $state(null);
    let typeModalOpen = $state(false);
    let phaseModalOpen = $state(false);
    let supplementModalOpen = $state(false);
    let deleteModalOpen = $state(false);
    let editingType = $state(null);
    let editingPhase = $state(null);
    let editingSupplement = $state(null);
    let pendingDeletion = $state(null);
    let deletionProcessing = $state(false);
    let planningCellsBusy = $state([]);

    const selectedType = $derived(protocolTypes.find((type) => type.id === selectedTypeId) ?? protocolTypes[0] ?? null);
    const phaseCount = $derived(protocolTypes.reduce((total, type) => total + type.phases.length, 0));
    const weekCount = $derived(protocolTypes.reduce(
        (total, type) => total + type.phases.reduce((phaseTotal, phase) => phaseTotal + phase.weeks.length, 0),
        0,
    ));
    const supplementCount = $derived(protocolTypes.reduce(
        (total, type) => total + type.phases.reduce((phaseTotal, phase) => phaseTotal + phase.supplements.length, 0),
        0,
    ));

    const typeForm = useForm({ name: '' });
    const phaseForm = useForm({
        protocol_type_id: '',
        name: '',
        description: '',
        required: false,
    });
    const supplementForm = useForm({
        protocol_type_phase_id: '',
        name: '',
        description: '',
        supplement_type: 'supplement',
        add_by_default: false,
        max_aantal_in_fase: '',
        min_aantal_per_week: 4,
        rust_periode_in_weken: 2,
    });

    function createType() {
        editingType = null;
        $typeForm.defaults({ name: '' });
        $typeForm.reset();
        $typeForm.clearErrors();
        typeModalOpen = true;
    }

    function editType(type) {
        editingType = type;
        $typeForm.defaults({ name: type.name });
        $typeForm.reset();
        $typeForm.clearErrors();
        typeModalOpen = true;
    }

    function submitType(event) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => (typeModalOpen = false) };

        if (editingType) {
            $typeForm.put(`/admin/protocol-settings/types/${editingType.id}`, options);
        } else {
            $typeForm.post('/admin/protocol-settings/types', options);
        }
    }

    function removeType(type) {
        requestDeletion({
            title: `Remove ${type.name}?`,
            description: 'All phases, weeks and supplements in this protocol type will also be removed.',
            url: `/admin/protocol-settings/types/${type.id}`,
            onSuccess: () => {
                if (selectedTypeId === type.id) {
                    selectedTypeId = protocolTypes.find((candidate) => candidate.id !== type.id)?.id ?? null;
                }
            },
        });
    }

    function createPhase() {
        if (!selectedType) return;
        editingPhase = null;
        $phaseForm.defaults({
            protocol_type_id: selectedType.id,
            name: '',
            description: '',
            required: false,
        });
        $phaseForm.reset();
        $phaseForm.clearErrors();
        phaseModalOpen = true;
    }

    function editPhase(phase) {
        editingPhase = phase;
        $phaseForm.defaults({
            protocol_type_id: phase.protocol_type_id,
            name: phase.name,
            description: phase.description ?? '',
            required: phase.required,
        });
        $phaseForm.reset();
        $phaseForm.clearErrors();
        phaseModalOpen = true;
    }

    function submitPhase(event) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                selectedTypeId = $phaseForm.protocol_type_id;
                phaseModalOpen = false;
            },
        };

        if (editingPhase) {
            $phaseForm.put(`/admin/protocol-settings/phases/${editingPhase.id}`, options);
        } else {
            $phaseForm.post('/admin/protocol-settings/phases', options);
        }
    }

    function removePhase(phase) {
        requestDeletion({
            title: `Remove ${phase.name}?`,
            description: 'All weeks and supplements in this phase will also be removed.',
            url: `/admin/protocol-settings/phases/${phase.id}`,
        });
    }

    function addWeek(phase) {
        router.post(`/admin/protocol-settings/phases/${phase.id}/weeks`, {}, { preserveScroll: true });
    }

    function removeWeek(week) {
        requestDeletion({
            title: `Remove Week ${week.number}?`,
            description: 'The remaining weeks will be renumbered automatically.',
            url: `/admin/protocol-settings/weeks/${week.id}`,
        });
    }

    function createSupplement(phase) {
        editingSupplement = null;
        $supplementForm.defaults({
            protocol_type_phase_id: phase.id,
            name: '',
            description: '',
            supplement_type: 'supplement',
            add_by_default: false,
            max_aantal_in_fase: '',
            min_aantal_per_week: 4,
            rust_periode_in_weken: 2,
        });
        $supplementForm.reset();
        $supplementForm.clearErrors();
        supplementModalOpen = true;
    }

    function editSupplement(supplement) {
        editingSupplement = supplement;
        $supplementForm.defaults({
            protocol_type_phase_id: supplement.protocol_type_phase_id,
            name: supplement.name,
            description: supplement.description ?? '',
            supplement_type: supplement.supplement_type,
            add_by_default: supplement.add_by_default,
            max_aantal_in_fase: supplement.max_aantal_in_fase ?? '',
            min_aantal_per_week: supplement.min_aantal_per_week,
            rust_periode_in_weken: supplement.rust_periode_in_weken,
        });
        $supplementForm.reset();
        $supplementForm.clearErrors();
        supplementModalOpen = true;
    }

    function submitSupplement(event) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => (supplementModalOpen = false) };

        if (editingSupplement) {
            $supplementForm.put(`/admin/protocol-settings/supplements/${editingSupplement.id}`, options);
        } else {
            $supplementForm.post('/admin/protocol-settings/supplements', options);
        }
    }

    function removeSupplement(supplement) {
        requestDeletion({
            title: `Remove ${supplement.name}?`,
            description: 'This supplement will no longer be available in this phase.',
            url: `/admin/protocol-settings/supplements/${supplement.id}`,
        });
    }

    function requestDeletion(deletion) {
        pendingDeletion = deletion;
        deleteModalOpen = true;
    }

    function confirmDeletion() {
        if (!pendingDeletion) return;
        deletionProcessing = true;
        router.delete(pendingDeletion.url, {
            preserveScroll: true,
            onSuccess: () => {
                pendingDeletion?.onSuccess?.();
                deleteModalOpen = false;
                pendingDeletion = null;
            },
            onFinish: () => (deletionProcessing = false),
        });
    }

    function isSupplementScheduled(supplement, week) {
        return supplement.weeks.some((scheduledWeek) => scheduledWeek.id === week.id);
    }

    function planningCellKey(supplement, week) {
        return `${supplement.id}:${week.id}`;
    }

    function toggleSupplementWeek(supplement, week) {
        const key = planningCellKey(supplement, week);
        if (planningCellsBusy.includes(key)) return;

        planningCellsBusy = [...planningCellsBusy, key];
        const url = `/admin/protocol-settings/supplements/${supplement.id}/weeks/${week.id}`;
        const options = {
            preserveScroll: true,
            onFinish: () => (planningCellsBusy = planningCellsBusy.filter((busyKey) => busyKey !== key)),
        };

        if (isSupplementScheduled(supplement, week)) {
            router.delete(url, options);
        } else {
            router.put(url, {}, options);
        }
    }

    const typeOptions = $derived(protocolTypes.map((type) => ({ value: type.id, label: type.name })));
    const phaseOptions = $derived(protocolTypes.flatMap((type) => type.phases.map((phase) => ({
        value: phase.id,
        label: `${type.name} · ${phase.name}`,
    }))));
    const supplementTypeOptions = [
        { value: 'kruid', label: 'Kruid' },
        { value: 'mineraal', label: 'Mineraal' },
        { value: 'supplement', label: 'Supplement' },
    ];
    const supplementTypeLabel = (value) => supplementTypeOptions.find((type) => type.value === value)?.label ?? value;
</script>

<AdminLayout title="Protocol Settings">
    <PageHeader
        title="Protocol Settings"
        description="Configure reusable protocol types, phases, weeks and supplements."
    >
        {#snippet actions()}
            <div class="hidden items-center gap-5 text-sm text-muted-foreground md:flex">
                <span><strong class="text-foreground">{protocolTypes.length}</strong> types</span>
                <span><strong class="text-foreground">{phaseCount}</strong> phases</span>
                <span><strong class="text-foreground">{weekCount}</strong> weeks</span>
                <span><strong class="text-foreground">{supplementCount}</strong> supplements</span>
            </div>
        {/snippet}
    </PageHeader>

    <div class="overflow-hidden rounded-xl border bg-white shadow-sm lg:grid lg:min-h-[650px] lg:grid-cols-[300px_minmax(0,1fr)]">
        <aside class="border-b bg-muted/20 lg:border-b-0 lg:border-r">
            <div class="flex items-center justify-between border-b px-5 py-4">
                <div>
                    <p class="text-sm font-semibold">Protocol types</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">Choose a type to configure</p>
                </div>
                <Button size="icon" variant="outline" onclick={createType} aria-label="New protocol type" title="New protocol type">
                    <Plus class="size-4" />
                </Button>
            </div>

            {#if protocolTypes.length}
                <div class="space-y-1 p-3">
                    {#each protocolTypes as type (type.id)}
                        <div
                            class:selected={selectedType?.id === type.id}
                            class="group flex items-center rounded-lg border border-transparent transition-colors hover:bg-white [&.selected]:border-primary/20 [&.selected]:bg-primary/10"
                        >
                            <button
                                class="min-w-0 flex-1 px-3 py-3 text-left"
                                onclick={() => (selectedTypeId = type.id)}
                            >
                                <span class="block truncate text-sm font-semibold">{type.name}</span>
                                <span class="mt-1 block text-xs text-muted-foreground">
                                    {type.phases.length} {type.phases.length === 1 ? 'phase' : 'phases'}
                                </span>
                            </button>
                            <ChevronRight class="mr-3 size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                        </div>
                    {/each}
                </div>
            {:else}
                <div class="px-6 py-14 text-center">
                    <div class="mx-auto flex size-11 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Layers3 class="size-5" />
                    </div>
                    <p class="mt-4 text-sm font-semibold">No protocol types yet</p>
                    <p class="mt-1 text-xs leading-5 text-muted-foreground">Create the first type to start defining its phases.</p>
                    <Button class="mt-4" size="sm" onclick={createType}><Plus class="size-4" /> New type</Button>
                </div>
            {/if}
        </aside>

        <section class="min-w-0">
            {#if selectedType}
                {#key selectedType.id}
                    <div in:fade={{ duration: 160 }}>
                        <div class="flex flex-wrap items-start justify-between gap-4 border-b px-5 py-5 sm:px-7">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-primary">Selected protocol type</p>
                                <h3 class="mt-1 text-xl font-bold tracking-tight">{selectedType.name}</h3>
                                <p class="mt-1 text-sm text-muted-foreground">{selectedType.phases.length} configured {selectedType.phases.length === 1 ? 'phase' : 'phases'}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button variant="outline" size="sm" onclick={() => editType(selectedType)}>
                                    <Pencil class="size-4" /> Edit type
                                </Button>
                                <Button variant="ghost" size="icon" onclick={() => removeType(selectedType)} aria-label="Remove protocol type" title="Remove protocol type">
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </div>
                        </div>

                        <div class="p-5 sm:p-7">
                            <div class="mb-4 flex items-center justify-between gap-3">
                                <div>
                                    <h4 class="font-semibold">Phases</h4>
                                    <p class="mt-0.5 text-xs text-muted-foreground">Each phase can span any number of automatically numbered weeks.</p>
                                </div>
                                <Button size="sm" onclick={createPhase}><Plus class="size-4" /> Add phase</Button>
                            </div>

                            {#if selectedType.phases.length}
                                <div class="divide-y rounded-xl border">
                                    {#each selectedType.phases as phase, index (phase.id)}
                                        <article class="p-5 transition-colors hover:bg-muted/10">
                                            <div class="flex items-start gap-4">
                                                <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                                                    {index + 1}
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <h5 class="font-semibold">{phase.name}</h5>
                                                        {#if phase.required}
                                                            <Badge variant="success"><Check class="mr-1 size-3" /> Required</Badge>
                                                        {:else}
                                                            <Badge variant="muted">Optional</Badge>
                                                        {/if}
                                                    </div>
                                                    {#if phase.description}
                                                        <p class="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">{phase.description}</p>
                                                    {/if}
                                                </div>
                                                <div class="flex shrink-0 items-center gap-1">
                                                    <Button size="icon" variant="ghost" onclick={() => editPhase(phase)} aria-label={`Edit ${phase.name}`} title="Edit phase">
                                                        <Pencil class="size-4" />
                                                    </Button>
                                                    <Button size="icon" variant="ghost" onclick={() => removePhase(phase)} aria-label={`Remove ${phase.name}`} title="Remove phase">
                                                        <Trash2 class="size-4 text-destructive" />
                                                    </Button>
                                                </div>
                                            </div>

                                            <div class="ml-0 mt-4 rounded-lg bg-muted/40 p-3 sm:ml-13">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="mr-1 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                        <CalendarDays class="size-4" /> Weeks
                                                    </span>
                                                    {#each phase.weeks as week (week.id)}
                                                        <span class="group/week inline-flex h-8 items-center gap-1 rounded-md border bg-white pl-3 pr-1 text-xs font-medium shadow-sm transition-transform hover:-translate-y-0.5">
                                                            Week {week.number}
                                                            <button
                                                                class="flex size-6 items-center justify-center rounded text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                                                onclick={() => removeWeek(week)}
                                                                aria-label={`Remove Week ${week.number}`}
                                                                title={`Remove Week ${week.number}`}
                                                            >
                                                                <X class="size-3" />
                                                            </button>
                                                        </span>
                                                    {/each}
                                                    <button
                                                        class="inline-flex h-8 items-center gap-1 rounded-md border border-dashed border-primary/50 px-2.5 text-xs font-semibold text-primary transition-colors hover:border-primary hover:bg-primary/5"
                                                        onclick={() => addWeek(phase)}
                                                    >
                                                        <Plus class="size-3" /> Add week
                                                    </button>
                                                </div>
                                                {#if !phase.weeks.length}
                                                    <p class="mt-2 text-xs text-muted-foreground">This phase has no weeks yet.</p>
                                                {/if}
                                            </div>

                                            <div class="ml-0 mt-5 border-t pt-4 sm:ml-13">
                                                <div class="flex flex-wrap items-center justify-between gap-3">
                                                    <div>
                                                        <p class="text-sm font-semibold">Supplements</p>
                                                        <p class="mt-0.5 text-xs text-muted-foreground">Supplements available within this phase.</p>
                                                    </div>
                                                    <Button size="sm" variant="outline" onclick={() => createSupplement(phase)}>
                                                        <Plus class="size-4" /> Add supplement
                                                    </Button>
                                                </div>

                                                {#if phase.supplements.length}
                                                    <div class="mt-3 divide-y rounded-lg border bg-white">
                                                        {#each phase.supplements as supplement (supplement.id)}
                                                            <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start">
                                                                <div class="min-w-0 flex-1">
                                                                    <div class="flex flex-wrap items-center gap-2">
                                                                        <p class="font-semibold">{supplement.name}</p>
                                                                        <Badge variant="muted">{supplementTypeLabel(supplement.supplement_type)}</Badge>
                                                                        {#if supplement.add_by_default}<Badge variant="success">Added by default</Badge>{/if}
                                                                    </div>
                                                                    {#if supplement.description}
                                                                        <p class="mt-1 text-sm leading-5 text-muted-foreground">{supplement.description}</p>
                                                                    {/if}
                                                                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
                                                                        <span><strong class="text-foreground">{supplement.max_aantal_in_fase ?? '∞'}</strong> max in phase</span>
                                                                        <span><strong class="text-foreground">{supplement.min_aantal_per_week}</strong> min per week</span>
                                                                        <span><strong class="text-foreground">{supplement.rust_periode_in_weken}</strong> rest weeks</span>
                                                                    </div>
                                                                </div>
                                                                <div class="flex shrink-0 items-center gap-1 self-end sm:self-start">
                                                                    <Button size="icon" variant="ghost" onclick={() => editSupplement(supplement)} aria-label={`Edit ${supplement.name}`} title="Edit supplement">
                                                                        <Pencil class="size-4" />
                                                                    </Button>
                                                                    <Button size="icon" variant="ghost" onclick={() => removeSupplement(supplement)} aria-label={`Remove ${supplement.name}`} title="Remove supplement">
                                                                        <Trash2 class="size-4 text-destructive" />
                                                                    </Button>
                                                                </div>
                                                            </div>
                                                        {/each}
                                                    </div>
                                                {:else}
                                                    <p class="mt-3 rounded-lg border border-dashed px-4 py-5 text-center text-xs text-muted-foreground">No supplements configured for this phase.</p>
                                                {/if}

                                                <div class="mt-5 border-t pt-4">
                                                    <div>
                                                        <p class="text-sm font-semibold">Supplementplanning</p>
                                                        <p class="mt-0.5 text-xs text-muted-foreground">Geef per week aan welke supplementen worden toegediend.</p>
                                                    </div>

                                                    {#if phase.supplements.length && phase.weeks.length}
                                                        <div class="mt-3 overflow-x-auto rounded-lg border bg-white">
                                                            <table class="min-w-max border-separate border-spacing-0 text-sm">
                                                                <thead>
                                                                    <tr>
                                                                        <th class="sticky left-0 z-10 min-w-48 border-b border-r bg-muted px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                                            Supplement
                                                                        </th>
                                                                        {#each phase.weeks as week (week.id)}
                                                                            <th class="min-w-20 border-b bg-muted/70 px-2 py-2.5 text-center text-xs font-semibold text-muted-foreground">
                                                                                Week {week.number}
                                                                            </th>
                                                                        {/each}
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    {#each phase.supplements as supplement (supplement.id)}
                                                                        <tr>
                                                                            <th class="sticky left-0 z-10 border-r border-t bg-white px-4 py-3 text-left first:border-t-0">
                                                                                <span class="block font-semibold">{supplement.name}</span>
                                                                                <span class="mt-0.5 block text-xs font-normal text-muted-foreground">{supplementTypeLabel(supplement.supplement_type)}</span>
                                                                            </th>
                                                                            {#each phase.weeks as week (week.id)}
                                                                                {@const scheduled = isSupplementScheduled(supplement, week)}
                                                                                {@const busy = planningCellsBusy.includes(planningCellKey(supplement, week))}
                                                                                <td class="border-t px-3 py-2 text-center first:border-t-0">
                                                                                    <button
                                                                                        class="mx-auto flex size-8 items-center justify-center rounded-md border transition-all hover:-translate-y-0.5 hover:border-primary disabled:cursor-wait disabled:opacity-50"
                                                                                        class:border-primary={scheduled}
                                                                                        class:bg-primary={scheduled}
                                                                                        class:text-primary-foreground={scheduled}
                                                                                        class:bg-muted={!scheduled}
                                                                                        onclick={() => toggleSupplementWeek(supplement, week)}
                                                                                        disabled={busy}
                                                                                        aria-pressed={scheduled}
                                                                                        aria-label={`${supplement.name} in week ${week.number}`}
                                                                                        title={scheduled ? 'Wordt toegediend' : 'Wordt niet toegediend'}
                                                                                    >
                                                                                        {#if scheduled}<Check class="size-4" />{/if}
                                                                                    </button>
                                                                                </td>
                                                                            {/each}
                                                                        </tr>
                                                                    {/each}
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    {:else}
                                                        <p class="mt-3 rounded-lg border border-dashed px-4 py-5 text-center text-xs text-muted-foreground">
                                                            Voeg minimaal één week en één supplement toe om de planning te maken.
                                                        </p>
                                                    {/if}
                                                </div>
                                            </div>
                                        </article>
                                    {/each}
                                </div>
                            {:else}
                                <div class="rounded-xl border border-dashed px-6 py-16 text-center">
                                    <div class="mx-auto flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                        <CalendarDays class="size-5" />
                                    </div>
                                    <p class="mt-4 text-sm font-semibold">No phases configured</p>
                                    <p class="mt-1 text-xs text-muted-foreground">Add the first phase for {selectedType.name}.</p>
                                    <Button class="mt-4" size="sm" onclick={createPhase}><Plus class="size-4" /> Add phase</Button>
                                </div>
                            {/if}
                        </div>
                    </div>
                {/key}
            {:else}
                <div class="hidden min-h-[500px] items-center justify-center p-8 text-center lg:flex">
                    <div>
                        <Layers3 class="mx-auto size-8 text-muted-foreground" />
                        <p class="mt-3 text-sm font-semibold">Create a protocol type to begin</p>
                    </div>
                </div>
            {/if}
        </section>
    </div>

    <Modal bind:open={typeModalOpen} title={editingType ? 'Edit protocol type' : 'New protocol type'}>
        <form id="protocol-type-form" class="space-y-4" onsubmit={submitType}>
            <Field label="Name" error={$typeForm.errors.name}>
                <Input bind:value={$typeForm.name} placeholder="e.g. Digestive recovery" autofocus />
            </Field>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (typeModalOpen = false)}>Cancel</Button>
            <Button type="submit" form="protocol-type-form" disabled={$typeForm.processing}>
                {editingType ? 'Save changes' : 'Create type'}
            </Button>
        {/snippet}
    </Modal>

    <Modal
        bind:open={phaseModalOpen}
        title={editingPhase ? 'Edit phase' : 'New phase'}
        description="Configure the phase details and add its weeks from the overview."
        size="lg"
    >
        <form id="protocol-phase-form" class="space-y-4" onsubmit={submitPhase}>
            <Field label="Protocol type" error={$phaseForm.errors.protocol_type_id}>
                <Select bind:value={$phaseForm.protocol_type_id} options={typeOptions} />
            </Field>
            <Field label="Name" error={$phaseForm.errors.name}>
                <Input bind:value={$phaseForm.name} placeholder="e.g. Stabilisation" />
            </Field>
            <Field label="Description" error={$phaseForm.errors.description} hint="A short explanation of this phase.">
                <Textarea bind:value={$phaseForm.description} rows="4" />
            </Field>
            <label class="flex items-start gap-3 rounded-lg border p-4">
                <input type="checkbox" bind:checked={$phaseForm.required} class="mt-0.5 size-4 rounded border-input accent-primary" />
                <span>
                    <span class="block text-sm font-semibold">Required phase</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">This phase must be included when using this protocol type.</span>
                </span>
            </label>
            {#if $phaseForm.errors.required}<p class="text-xs text-destructive">{$phaseForm.errors.required}</p>{/if}
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (phaseModalOpen = false)}>Cancel</Button>
            <Button type="submit" form="protocol-phase-form" disabled={$phaseForm.processing}>
                {editingPhase ? 'Save changes' : 'Create phase'}
            </Button>
        {/snippet}
    </Modal>

    <Modal
        bind:open={supplementModalOpen}
        title={editingSupplement ? 'Supplement bewerken' : 'Nieuw supplement'}
        description="Stel in hoe dit supplement binnen een protocolfase kan worden gebruikt."
        size="lg"
    >
        <form id="supplement-form" class="space-y-4" onsubmit={submitSupplement}>
            <Field label="Protocolfase" error={$supplementForm.errors.protocol_type_phase_id}>
                <Select bind:value={$supplementForm.protocol_type_phase_id} options={phaseOptions} />
            </Field>
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="Naam" error={$supplementForm.errors.name}>
                    <Input bind:value={$supplementForm.name} placeholder="bijv. Psylliumzaad" />
                </Field>
                <Field label="Supplementtype" error={$supplementForm.errors.supplement_type}>
                    <Select bind:value={$supplementForm.supplement_type} options={supplementTypeOptions} />
                </Field>
            </div>
            <Field label="Beschrijving" error={$supplementForm.errors.description}>
                <Textarea bind:value={$supplementForm.description} rows="3" />
            </Field>
            <label class="flex items-start gap-3 rounded-lg border p-4">
                <input type="checkbox" bind:checked={$supplementForm.add_by_default} class="mt-0.5 size-4 rounded border-input accent-primary" />
                <span>
                    <span class="block text-sm font-semibold">Standaard toevoegen</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">Selecteer dit supplement standaard wanneer de fase aan een protocol wordt toegevoegd.</span>
                </span>
            </label>
            {#if $supplementForm.errors.add_by_default}<p class="text-xs text-destructive">{$supplementForm.errors.add_by_default}</p>{/if}
            <div class="grid gap-4 sm:grid-cols-3">
                <Field label="Maximum in fase" error={$supplementForm.errors.max_aantal_in_fase} hint="Leeg betekent onbeperkt.">
                    <Input type="number" min="1" bind:value={$supplementForm.max_aantal_in_fase} placeholder="Onbeperkt" />
                </Field>
                <Field label="Minimum per week" error={$supplementForm.errors.min_aantal_per_week}>
                    <Input type="number" min="0" bind:value={$supplementForm.min_aantal_per_week} />
                </Field>
                <Field label="Rustperiode (weken)" error={$supplementForm.errors.rust_periode_in_weken}>
                    <Input type="number" min="0" bind:value={$supplementForm.rust_periode_in_weken} />
                </Field>
            </div>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (supplementModalOpen = false)}>Annuleren</Button>
            <Button type="submit" form="supplement-form" disabled={$supplementForm.processing}>
                {editingSupplement ? 'Wijzigingen opslaan' : 'Supplement aanmaken'}
            </Button>
        {/snippet}
    </Modal>

    <Modal bind:open={deleteModalOpen} title={pendingDeletion?.title ?? 'Remove item?'} description={pendingDeletion?.description} size="sm">
        <p class="text-sm text-muted-foreground">This action cannot be undone.</p>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (deleteModalOpen = false)}>Cancel</Button>
            <Button variant="destructive" onclick={confirmDeletion} disabled={deletionProcessing}>Remove</Button>
        {/snippet}
    </Modal>
</AdminLayout>
