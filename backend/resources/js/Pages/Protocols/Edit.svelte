<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import Field from '$lib/components/Field.svelte';
    import { Link, useForm } from '@inertiajs/svelte';
    import { untrack } from 'svelte';
    import { Button, Input, Select, Textarea } from '$lib/components/ui';
    import {
        ArrowLeft,
        Check,
        ChevronRight,
        Eye,
        Lock,
        Plus,
        Save,
        Trash2,
        X,
    } from '@lucide/svelte';

    let { protocol, horses, therapists, protocolTypes, selectedHorseId = null } = $props();
    const initialProtocol = untrack(() => protocol);
    const initialProtocolTypes = untrack(() => protocolTypes);
    const initialSelectedHorseId = untrack(() => selectedHorseId);
    const isNew = !initialProtocol;

    let nextClientKey = 0;
    const makeClientKey = () => `phase-${Date.now()}-${nextClientKey++}`;
    const initialProtocolTypeId = initialProtocol?.protocol_type_id ?? initialProtocolTypes[0]?.id ?? '';
    const initialPhaseDefinitions = initialProtocolTypes.find((type) => type.id === initialProtocolTypeId)?.phases ?? [];
    const initialPhaseRows = initialProtocol?.phases?.length
        ? initialProtocol.phases
        : initialPhaseDefinitions.filter((phase) => phase.required).map((phase, index) => ({
            protocol_type_phase_id: phase.id,
            title: phase.name,
            state: index === 0 ? 'active' : 'upcoming',
            week_start: '',
            week_end: '',
            chip_label: 'Verplicht',
            items: [],
            supplement_ids: (phase.supplements ?? []).filter((supplement) => supplement.add_by_default).map((supplement) => supplement.id),
        }));
    const initialPhases = initialPhaseRows.map((phase) => ({
        id: phase.id ?? null,
        client_key: phase.id ?? makeClientKey(),
        protocol_type_phase_id: phase.protocol_type_phase_id,
        title: phase.title ?? '',
        state: phase.state ?? 'upcoming',
        week_start: phase.week_start ?? '',
        week_end: phase.week_end ?? '',
        chip_label: phase.chip_label ?? '',
        items: (phase.items ?? []).map((item) => ({ id: item.id ?? null, label: item.label ?? '' })),
        supplement_ids: phase.supplement_ids
            ?? (phase.supplements ?? []).map((selection) => selection.supplement_id),
    }));

    const form = useForm({
        horse_id: initialProtocol?.horse_id ?? initialSelectedHorseId ?? '',
        protocol_type_id: initialProtocolTypeId,
        therapist_id: initialProtocol?.therapist_id ?? '',
        title: initialProtocol?.title ?? '',
        subtitle_analyse: initialProtocol?.subtitle_analyse ?? '',
        subtitle_protocol: initialProtocol?.subtitle_protocol ?? '',
        subtitle_calendar: initialProtocol?.subtitle_calendar ?? '',
        total_weeks: initialProtocol?.total_weeks ?? '',
        current_week: initialProtocol?.current_week ?? 1,
        started_at: initialProtocol?.started_at?.slice(0, 10) ?? '',
        status: initialProtocol?.status ?? 'paused',
        analysis: { cause: initialProtocol?.analysis?.cause ?? '' },
        advice: (initialProtocol?.analysis?.advice ?? []).map((row) => ({
            id: row.id ?? null,
            icon_key: row.icon_key ?? 'leaf',
            title: row.title ?? '',
            body: row.body ?? '',
        })),
        phases: initialPhases,
        tasks: (initialProtocol?.tasks ?? []).map((task) => ({
            id: task.id ?? null,
            phase_key: initialPhases.find((phase) => phase.id === task.phase_id)?.client_key ?? '',
            label: task.label ?? '',
            meta: task.meta ?? '',
            kind: task.kind ?? 'other',
            active_from: task.active_from?.slice(0, 10) ?? '',
            active_until: task.active_until?.slice(0, 10) ?? '',
            reference_item_id: task.reference_item_id ?? '',
        })),
    });

    let activePhaseKey = $state(initialPhases[0]?.client_key ?? null);
    let phaseToAddId = $state('');
    let overviewOpen = $state(false);

    const selectedHorse = $derived(horses.find((horse) => horse.id === $form.horse_id));
    const activePhaseIndex = $derived($form.phases.findIndex((phase) => phase.client_key === activePhaseKey));
    const activePhase = $derived(activePhaseIndex >= 0 ? $form.phases[activePhaseIndex] : null);

    const horseOptions = $derived(horses.map((horse) => ({
        value: horse.id,
        label: `${horse.name}${horse.owner?.name ? ` · ${horse.owner.name}` : ''}`,
    })));
    const therapistOptions = $derived(therapists.map((therapist) => ({
        value: therapist.id,
        label: `${therapist.name}${therapist.title ? ` · ${therapist.title}` : ''}`,
    })));
    const protocolTypeOptions = $derived(protocolTypes.map((protocolType) => ({
        value: protocolType.id,
        label: protocolType.name,
    })));
    const selectedProtocolType = $derived(protocolTypes.find((protocolType) => protocolType.id === $form.protocol_type_id));
    const activePhaseDefinition = $derived(selectedProtocolType?.phases?.find(
        (definition) => definition.id === activePhase?.protocol_type_phase_id,
    ));
    const availableSupplements = $derived(activePhaseDefinition?.supplements ?? []);
    const selectedSupplements = $derived(availableSupplements.filter(
        (supplement) => activePhase?.supplement_ids?.includes(supplement.id),
    ));
    const availablePhaseDefinitions = $derived((selectedProtocolType?.phases ?? [])
        .filter((definition) => !$form.phases.some((phase) => phase.protocol_type_phase_id === definition.id)));
    const availablePhaseOptions = $derived(availablePhaseDefinitions.map((definition) => ({
        value: definition.id,
        label: `${definition.name}${definition.required ? ' · verplicht' : ''}`,
    })));
    const stateOptions = [
        { value: 'done', label: 'Completed' },
        { value: 'active', label: 'Active' },
        { value: 'upcoming', label: 'Upcoming' },
    ];
    const statusOptions = [
        { value: 'paused', label: 'Paused' },
        { value: 'active', label: 'Active' },
        { value: 'completed', label: 'Completed' },
    ];
    function phaseDefinition(phase) {
        return selectedProtocolType?.phases?.find((definition) => definition.id === phase?.protocol_type_phase_id);
    }

    function isRequiredPhase(phase) {
        return phaseDefinition(phase)?.required ?? false;
    }

    function makePhaseFromDefinition(definition, active = false) {
        return {
            id: null,
            client_key: makeClientKey(),
            protocol_type_phase_id: definition.id,
            title: definition.name,
            state: active ? 'active' : 'upcoming',
            week_start: '',
            week_end: '',
            chip_label: definition.required ? 'Verplicht' : '',
            items: [],
            supplement_ids: (definition.supplements ?? [])
                .filter((supplement) => supplement.add_by_default)
                .map((supplement) => supplement.id),
        };
    }

    function changeProtocolType() {
        const definitions = protocolTypes.find((type) => type.id === $form.protocol_type_id)?.phases ?? [];
        $form.phases = definitions
            .filter((definition) => definition.required)
            .map((definition, index) => makePhaseFromDefinition(definition, index === 0));
        $form.tasks = [];
        activePhaseKey = $form.phases[0]?.client_key ?? null;
        phaseToAddId = '';
    }

    function addPhase() {
        const definition = availablePhaseDefinitions.find((phase) => phase.id === phaseToAddId);
        if (!definition) return;

        const phase = makePhaseFromDefinition(definition, $form.phases.length === 0);
        $form.phases = [...$form.phases, phase];
        activePhaseKey = phase.client_key;
        phaseToAddId = '';
    }

    function removeActivePhase() {
        if (!activePhase || isRequiredPhase(activePhase)) return;

        const removedKey = activePhase.client_key;
        const removedIndex = activePhaseIndex;
        const remainingPhases = $form.phases.filter((phase) => phase.client_key !== removedKey);
        $form.phases = remainingPhases;
        $form.tasks = $form.tasks.filter((task) => task.phase_key !== removedKey);
        activePhaseKey = remainingPhases[Math.min(removedIndex, remainingPhases.length - 1)]?.client_key ?? null;
    }

    function addPhaseItem() {
        $form.phases[activePhaseIndex].items = [
            ...$form.phases[activePhaseIndex].items,
            { id: null, label: '' },
        ];
    }

    function removePhaseItem(itemIndex) {
        $form.phases[activePhaseIndex].items = $form.phases[activePhaseIndex].items.filter((_, index) => index !== itemIndex);
    }

    function toggleSupplement(supplementId) {
        if (!activePhase) return;

        const selectedIds = activePhase.supplement_ids ?? [];
        const supplementIds = selectedIds.includes(supplementId)
            ? selectedIds.filter((id) => id !== supplementId)
            : [...selectedIds, supplementId];
        $form.phases = $form.phases.map((phase, index) => index === activePhaseIndex
            ? { ...phase, supplement_ids: supplementIds }
            : phase);
    }

    function supplementTypeLabel(type) {
        return ({ kruid: 'Kruid', mineraal: 'Mineraal', supplement: 'Supplement' })[type] ?? type;
    }

    function supplementSchedule(supplement) {
        const weekNumbers = (supplement.weeks ?? []).map((week) => week.number);

        return weekNumbers.length ? `Week ${weekNumbers.join(', ')}` : 'Geen weekschema ingesteld';
    }

    function addAdvice() {
        $form.advice = [...$form.advice, { id: null, icon_key: 'leaf', title: '', body: '' }];
    }

    function removeAdvice(index) {
        $form.advice = $form.advice.filter((_, adviceIndex) => adviceIndex !== index);
    }

    function submit(event) {
        event.preventDefault();
        if (isNew) $form.post('/admin/protocols');
        else $form.put(`/admin/protocols/${protocol.id}`);
    }

    function errorFor(path) {
        return $form.errors[path];
    }

    function sexLabel(value) {
        return ({ merrie: 'mare', ruin: 'gelding', hengst: 'stallion' })[value] ?? value;
    }
</script>

<AdminLayout title={isNew ? 'New protocol' : 'Edit protocol'}>
    <div class="-mx-4 -my-6 min-h-[calc(100vh-4rem)] bg-[#FBF8F3] text-[#1B2A2A] lg:-mx-8">
        <form onsubmit={submit}>
            <div class="sticky top-16 z-10 flex min-h-16 flex-wrap items-center gap-3 border-b border-[#1B2A2A]/10 bg-white/95 px-4 py-3 backdrop-blur lg:px-8">
                <Link href="/admin/protocols" class="inline-flex items-center gap-1.5 text-sm font-semibold text-[#127A79] hover:text-[#0D5C5B]">
                    <ArrowLeft class="size-4" /> Protocols
                </Link>
                <div class="h-5 w-px bg-[#1B2A2A]/10"></div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-bold">{isNew ? 'Create horse protocol' : $form.title || 'Untitled protocol'}</div>
                    <div class="truncate text-xs text-[#1B2A2A]/50">{selectedHorse?.name ?? 'Select a horse to start'}</div>
                </div>
                <Button type="button" variant="outline" class="rounded-full" onclick={() => (overviewOpen = true)} disabled={!activePhase}>
                    <Eye class="size-4" /> Overview ({selectedSupplements.length})
                </Button>
                <Button type="submit" class="rounded-full bg-[#18BAB0] px-5 hover:bg-[#108A82]" disabled={$form.processing}>
                    <Save class="size-4" /> {$form.processing ? 'Saving…' : isNew ? 'Create protocol' : 'Save changes'}
                </Button>
            </div>

            {#if Object.keys($form.errors).length}
                <div class="mx-auto mt-5 max-w-[1180px] px-4 lg:px-8">
                    <div class="rounded-2xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                        Some fields still need attention. The relevant messages are shown beside them.
                    </div>
                </div>
            {/if}

            <div class="mx-auto grid max-w-[1180px] gap-6 px-4 py-7 lg:grid-cols-[300px_minmax(0,1fr)] lg:px-8">
                <aside class="space-y-4 lg:sticky lg:top-40 lg:self-start">
                    <section class="rounded-[20px] border border-[#1B2A2A]/10 bg-white p-5">
                        <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.12em] text-[#108A82]">Horse</div>
                        <Field label="Protocol for" error={$form.errors.horse_id}>
                            <Select bind:value={$form.horse_id} placeholder="Select a horse" options={horseOptions} disabled={!isNew} />
                        </Field>
                        {#if selectedHorse}
                            <div class="mt-4 border-t border-[#1B2A2A]/10 pt-4">
                                <div class="font-bold">{selectedHorse.name}</div>
                                <div class="mt-1 text-xs leading-5 text-[#1B2A2A]/55">
                                    {[selectedHorse.breed, sexLabel(selectedHorse.sex), selectedHorse.age ? `${selectedHorse.age} years` : null, selectedHorse.weight_kg ? `${selectedHorse.weight_kg} kg` : null].filter(Boolean).join(' · ') || 'Horse details not completed'}
                                </div>
                                {#if selectedHorse.owner}
                                    <div class="mt-3 text-xs text-[#1B2A2A]/65">Owner: <span class="font-semibold">{selectedHorse.owner.name}</span></div>
                                {/if}
                            </div>
                        {:else}
                            <p class="mt-3 text-xs leading-5 text-[#1B2A2A]/50">Choose the horse this protocol belongs to. The selection is fixed after creation.</p>
                        {/if}
                    </section>

                    <section class="rounded-[20px] bg-[#0D5C5B] p-5 text-[#FBF8F3]">
                        <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.12em] text-[#99E8DF]">Likely cause</div>
                        <textarea
                            bind:value={$form.analysis.cause}
                            rows="6"
                            placeholder="Summarize the analysis behind this protocol…"
                            class="w-full resize-y rounded-xl border border-white/20 bg-white/10 px-3 py-2 text-sm leading-6 text-white outline-none placeholder:text-white/45 focus:border-[#99E8DF] focus:ring-2 focus:ring-[#99E8DF]/30"
                        ></textarea>
                        {#if $form.errors['analysis.cause']}<p class="mt-1 text-xs text-red-200">{$form.errors['analysis.cause']}</p>{/if}
                    </section>

                    <section class="rounded-[20px] border border-[#1B2A2A]/10 bg-white p-5">
                        <div class="mb-3 text-[11px] font-bold uppercase tracking-[0.12em] text-[#108A82]">Detected topics</div>
                        {#if selectedHorse?.focus_topics?.length}
                            <div class="flex flex-wrap gap-1.5">
                                {#each selectedHorse.focus_topics as topic (topic.id)}
                                    <span class="rounded-full bg-[#EAFBF9] px-2.5 py-1 text-[11px] font-bold text-[#0E6F69]">{topic.title}</span>
                                {/each}
                            </div>
                        {:else}
                            <p class="text-xs leading-5 text-[#1B2A2A]/50">No focus topics are linked to this horse yet.</p>
                        {/if}
                        <p class="mt-3 text-xs leading-5 text-[#1B2A2A]/45">Topics come from the horse profile and help the therapist curate the protocol.</p>
                    </section>
                </aside>

                <main class="min-w-0 space-y-5">
                    <section class="rounded-[20px] border border-[#1B2A2A]/10 bg-white p-5 md:p-6">
                        <div class="mb-5 flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-bold">Protocol details</h2>
                                <p class="mt-1 text-sm text-[#1B2A2A]/50">Title, ownership, timing and what the customer sees.</p>
                            </div>
                            <span class="rounded-full bg-[#EAFBF9] px-3 py-1 text-xs font-bold capitalize text-[#0E6F69]">{$form.status}</span>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <Field label="Protocol type" error={$form.errors.protocol_type_id}>
                                <Select bind:value={$form.protocol_type_id} placeholder="Select a protocol type" options={protocolTypeOptions} onchange={changeProtocolType} />
                            </Field>
                            <Field label="Title" error={$form.errors.title}><Input bind:value={$form.title} placeholder="e.g. Nova's recovery plan" /></Field>
                            <Field label="Therapist" error={$form.errors.therapist_id}>
                                <Select bind:value={$form.therapist_id} placeholder="No therapist" options={therapistOptions} />
                            </Field>
                            <Field label="Status" error={$form.errors.status}><Select bind:value={$form.status} options={statusOptions} /></Field>
                            <Field label="Start date" error={$form.errors.started_at}><Input type="date" bind:value={$form.started_at} /></Field>
                            <Field label="Current week" error={$form.errors.current_week}><Input type="number" min="1" max="104" bind:value={$form.current_week} /></Field>
                            <Field label="Total weeks" error={$form.errors.total_weeks}><Input type="number" min="1" max="104" bind:value={$form.total_weeks} /></Field>
                            <Field label="Analysis subtitle" error={$form.errors.subtitle_analyse}><Input bind:value={$form.subtitle_analyse} placeholder="Shown under the analysis tab" /></Field>
                            <Field label="Protocol subtitle" error={$form.errors.subtitle_protocol}><Input bind:value={$form.subtitle_protocol} placeholder="e.g. Week 3 of 8 · Phase 1 active" /></Field>
                            <div class="md:col-span-2">
                                <Field label="Calendar subtitle" error={$form.errors.subtitle_calendar}><Input bind:value={$form.subtitle_calendar} placeholder="e.g. August 2026" /></Field>
                            </div>
                        </div>
                    </section>

                    <section>
                        <div class="mb-2 flex flex-wrap items-end justify-between gap-3">
                            <div class="text-[11px] font-bold uppercase tracking-[0.12em] text-[#1B2A2A]/45">Protocol phase</div>
                            {#if availablePhaseOptions.length}
                                <div class="flex items-center gap-2">
                                    <Select class="w-52" bind:value={phaseToAddId} placeholder="Choose a phase" options={availablePhaseOptions} />
                                    <Button type="button" variant="outline" size="sm" class="rounded-full" onclick={addPhase} disabled={!phaseToAddId}>
                                        <Plus class="size-4" /> Add phase
                                    </Button>
                                </div>
                            {/if}
                        </div>
                        <div class="flex gap-2 overflow-x-auto pb-2">
                            {#each $form.phases as phase, index (phase.client_key)}
                                <button
                                    type="button"
                                    onclick={() => (activePhaseKey = phase.client_key)}
                                    class={`min-w-44 flex-1 rounded-2xl border px-4 py-3 text-left transition ${phase.client_key === activePhaseKey ? 'border-[#18BAB0] bg-[#EAFBF9] shadow-sm' : 'border-[#1B2A2A]/10 bg-white hover:border-[#18BAB0]/50'}`}
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span class={`truncate text-sm font-bold ${phase.client_key === activePhaseKey ? 'text-[#0E6F69]' : ''}`}>{phase.title || `Phase ${index + 1}`}</span>
                                        <ChevronRight class={`size-3.5 shrink-0 transition-transform ${phase.client_key === activePhaseKey ? 'rotate-90 text-[#108A82]' : 'text-[#1B2A2A]/30'}`} />
                                    </div>
                                    <div class="mt-1 text-xs text-[#1B2A2A]/50">
                                        {phase.week_start !== '' ? `Week ${phase.week_start}${phase.week_end && phase.week_end !== phase.week_start ? `–${phase.week_end}` : ''}` : 'Timing not set'}
                                    </div>
                                    {#if isRequiredPhase(phase)}
                                        <div class="mt-2 inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-[0.08em] text-[#108A82]">
                                            <Lock class="size-3" /> Required
                                        </div>
                                    {/if}
                                </button>
                            {:else}
                                <div class="w-full rounded-2xl border border-dashed border-[#1B2A2A]/15 bg-white px-4 py-5 text-center text-sm text-[#1B2A2A]/45">
                                    Add an optional phase to start configuring this protocol.
                                </div>
                            {/each}
                        </div>
                        {#if $form.errors.phases}<p class="mt-1 text-xs text-destructive">{$form.errors.phases}</p>{/if}
                    </section>

                    {#if activePhase}
                        <section class="rounded-[20px] border border-[#1B2A2A]/10 bg-white p-5 md:p-6">
                            <div class="mb-5 flex items-center justify-between gap-3">
                                <h2 class="text-lg font-bold">Phase details</h2>
                                {#if isRequiredPhase(activePhase)}
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#EAFBF9] px-3 py-1 text-xs font-bold text-[#0E6F69]">
                                        <Lock class="size-3.5" /> Required phase
                                    </span>
                                {:else}
                                    <Button type="button" variant="ghost" size="sm" class="text-destructive hover:text-destructive" onclick={removeActivePhase}>
                                        <Trash2 class="size-4" /> Remove phase
                                    </Button>
                                {/if}
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <Field label="Phase title" error={errorFor(`phases.${activePhaseIndex}.title`)}><Input bind:value={$form.phases[activePhaseIndex].title} /></Field>
                                <Field label="State" error={errorFor(`phases.${activePhaseIndex}.state`)}><Select bind:value={$form.phases[activePhaseIndex].state} options={stateOptions} /></Field>
                                <Field label="Start week" error={errorFor(`phases.${activePhaseIndex}.week_start`)}><Input type="number" min="0" max="104" bind:value={$form.phases[activePhaseIndex].week_start} /></Field>
                                <Field label="End week" error={errorFor(`phases.${activePhaseIndex}.week_end`)}><Input type="number" min="0" max="104" bind:value={$form.phases[activePhaseIndex].week_end} /></Field>
                                <div class="md:col-span-2">
                                    <Field label="Status chip" error={errorFor(`phases.${activePhaseIndex}.chip_label`)}><Input bind:value={$form.phases[activePhaseIndex].chip_label} placeholder="e.g. Active · wk 1–4" /></Field>
                                </div>
                            </div>

                            <div class="mt-6 border-t border-[#1B2A2A]/10 pt-5">
                                <div class="mb-3 flex items-center justify-between gap-3">
                                    <div>
                                        <h3 class="text-sm font-bold">Phase summary</h3>
                                        <p class="mt-0.5 text-xs text-[#1B2A2A]/50">Short bullets shown in the customer’s protocol timeline.</p>
                                    </div>
                                    <Button type="button" variant="outline" size="sm" class="rounded-full" onclick={addPhaseItem}><Plus class="size-4" /> Add item</Button>
                                </div>
                                <div class="space-y-2">
                                    {#each $form.phases[activePhaseIndex].items as item, itemIndex (item.id ?? itemIndex)}
                                        <div class="flex items-start gap-2">
                                            <Check class="mt-2.5 size-4 shrink-0 text-[#18BAB0]" />
                                            <div class="flex-1">
                                                <Input bind:value={$form.phases[activePhaseIndex].items[itemIndex].label} placeholder="What happens in this phase?" />
                                                {#if errorFor(`phases.${activePhaseIndex}.items.${itemIndex}.label`)}<p class="mt-1 text-xs text-destructive">{errorFor(`phases.${activePhaseIndex}.items.${itemIndex}.label`)}</p>{/if}
                                            </div>
                                            <Button type="button" variant="ghost" size="icon" onclick={() => removePhaseItem(itemIndex)} aria-label="Remove phase item"><X class="size-4" /></Button>
                                        </div>
                                    {:else}
                                        <button type="button" onclick={addPhaseItem} class="w-full rounded-xl border border-dashed border-[#1B2A2A]/15 px-4 py-5 text-sm text-[#1B2A2A]/45 hover:border-[#18BAB0]/50 hover:text-[#108A82]">
                                            Add the first customer-facing summary item
                                        </button>
                                    {/each}
                                </div>
                            </div>
                        </section>

                        <section class="overflow-hidden rounded-[20px] border border-[#1B2A2A]/10 bg-white">
                            <div class="flex items-center justify-between gap-4 border-b border-[#1B2A2A]/10 px-5 py-4 md:px-6">
                                <div>
                                    <h2 class="text-base font-bold">Kruiden & supplementen</h2>
                                    <p class="mt-0.5 text-xs text-[#1B2A2A]/50">Selecteer de supplementen voor {activePhase.title || 'deze fase'}.</p>
                                </div>
                                <span class="shrink-0 text-xs font-semibold text-[#1B2A2A]/50">{selectedSupplements.length} van {availableSupplements.length} actief</span>
                            </div>

                            <div class="divide-y divide-[#1B2A2A]/8">
                                {#each availableSupplements as supplement (supplement.id)}
                                    {@const isSelected = activePhase.supplement_ids?.includes(supplement.id) ?? false}
                                    <div class={`flex items-start gap-3.5 px-5 py-4 transition-colors md:px-6 ${isSelected ? 'bg-white' : 'bg-[#FBF8F3]/55'}`}>
                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked={isSelected}
                                            aria-label={`${isSelected ? 'Verwijder' : 'Voeg toe'} ${supplement.name}`}
                                            onclick={() => toggleSupplement(supplement.id)}
                                            class={`relative mt-0.5 h-[23px] w-10 shrink-0 rounded-full transition-colors ${isSelected ? 'bg-[#18BAB0]' : 'bg-[#1B2A2A]/20'}`}
                                        >
                                            <span class={`absolute top-[2.5px] size-[18px] rounded-full bg-white shadow-sm transition-transform ${isSelected ? 'translate-x-[19px]' : 'translate-x-[2px]'}`}></span>
                                        </button>
                                        <div class={`min-w-0 flex-1 transition-opacity ${isSelected ? '' : 'opacity-60'}`}>
                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <span class="text-sm font-bold">{supplement.name}</span>
                                                <span class="rounded-full bg-[#EAFBF9] px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.08em] text-[#0E6F69]">{supplementTypeLabel(supplement.supplement_type)}</span>
                                                {#if supplement.add_by_default}
                                                    <span class="rounded-full bg-[#FDF4E4] px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.08em] text-[#7A5A16]">Standaard</span>
                                                {/if}
                                            </div>
                                            {#if supplement.description}
                                                <p class="mt-1 text-xs leading-5 text-[#1B2A2A]/55">{supplement.description}</p>
                                            {/if}
                                            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[10px] font-semibold text-[#1B2A2A]/45">
                                                <span>{supplementSchedule(supplement)}</span>
                                                <span>Min. {supplement.min_aantal_per_week}× per week</span>
                                                <span>Rust {supplement.rust_periode_in_weken} weken</span>
                                                {#if supplement.max_aantal_in_fase !== null}<span>Max. {supplement.max_aantal_in_fase} in fase</span>{/if}
                                            </div>
                                        </div>
                                    </div>
                                {:else}
                                    <div class="px-5 py-9 text-center md:px-6">
                                        <p class="text-sm font-semibold text-[#1B2A2A]/60">Geen supplementen beschikbaar voor deze fase.</p>
                                        <p class="mt-1 text-xs text-[#1B2A2A]/40">Voeg supplementen toe via Protocol Settings.</p>
                                    </div>
                                {/each}
                            </div>
                        </section>
                    {/if}

                    <section class="rounded-[20px] border border-[#1B2A2A]/10 bg-white p-5 md:p-6">
                        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-bold">Advice</h2>
                                <p class="mt-1 text-sm text-[#1B2A2A]/50">Recommendations shown beneath the likely cause in the app.</p>
                            </div>
                            <Button type="button" variant="outline" class="rounded-full" onclick={addAdvice}><Plus class="size-4" /> Add advice</Button>
                        </div>
                        <div class="space-y-3">
                            {#each $form.advice as advice, adviceIndex (advice.id ?? adviceIndex)}
                                <div class="rounded-2xl border border-[#1B2A2A]/10 p-4">
                                    <div class="grid gap-3 md:grid-cols-[150px_minmax(0,1fr)_40px]">
                                        <Field label="Icon" error={errorFor(`advice.${adviceIndex}.icon_key`)}>
                                            <Select bind:value={$form.advice[adviceIndex].icon_key} options={[
                                                { value: 'leaf', label: 'Nutrition' }, { value: 'run', label: 'Movement' }, { value: 'horse', label: 'Horse care' },
                                            ]} />
                                        </Field>
                                        <Field label="Title" error={errorFor(`advice.${adviceIndex}.title`)}><Input bind:value={$form.advice[adviceIndex].title} /></Field>
                                        <div class="pt-[26px]"><Button type="button" variant="ghost" size="icon" onclick={() => removeAdvice(adviceIndex)} aria-label="Remove advice"><Trash2 class="size-4 text-destructive" /></Button></div>
                                    </div>
                                    <div class="mt-3">
                                        <Field label="Advice text" error={errorFor(`advice.${adviceIndex}.body`)}><Textarea bind:value={$form.advice[adviceIndex].body} /></Field>
                                    </div>
                                </div>
                            {:else}
                                <button type="button" onclick={addAdvice} class="w-full rounded-2xl border border-dashed border-[#1B2A2A]/15 px-4 py-8 text-sm text-[#1B2A2A]/45 hover:border-[#18BAB0]/50 hover:text-[#108A82]">
                                    Add the first recommendation
                                </button>
                            {/each}
                        </div>
                    </section>
                </main>
            </div>
        </form>

        {#if overviewOpen}
            <div class="fixed inset-0 z-50 flex justify-end bg-[#0B4A49]/45">
                <button type="button" class="absolute inset-0 cursor-default" aria-label="Close overview" onclick={() => (overviewOpen = false)}></button>
                <div class="relative h-full w-full max-w-md overflow-y-auto bg-[#FBF8F3] p-6 shadow-2xl" role="dialog" aria-modal="true" aria-label="Phase overview">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-[0.12em] text-[#108A82]">Customer preview</div>
                            <h2 class="mt-1 text-xl font-bold">{activePhase?.title ?? 'Phase overview'}</h2>
                            <p class="mt-1 text-sm text-[#1B2A2A]/55">What {selectedHorse?.name ?? 'the horse owner'} will see for this phase.</p>
                        </div>
                        <Button type="button" variant="ghost" size="icon" onclick={() => (overviewOpen = false)} aria-label="Close overview"><X class="size-5" /></Button>
                    </div>

                    <div class="mt-6 space-y-6">
                        <section>
                            <div class="mb-2 text-[11px] font-bold uppercase tracking-[0.1em] text-[#108A82]">Phase summary</div>
                            <div class="space-y-2">
                                {#each activePhase?.items ?? [] as item (item.id ?? item.label)}
                                    <div class="flex items-start gap-2 rounded-xl border border-[#1B2A2A]/10 bg-white px-3 py-2.5 text-sm">
                                        <Check class="mt-0.5 size-4 shrink-0 text-[#18BAB0]" /> {item.label || 'Untitled item'}
                                    </div>
                                {:else}<p class="text-sm text-[#1B2A2A]/45">No phase summary items.</p>{/each}
                            </div>
                        </section>

                        {#if selectedSupplements.length}
                            <section>
                                <div class="mb-2 text-[11px] font-bold uppercase tracking-[0.1em] text-[#108A82]">Kruiden & supplementen</div>
                                <div class="space-y-2">
                                    {#each selectedSupplements as supplement (supplement.id)}
                                        <div class="flex items-start justify-between gap-3 rounded-xl border border-[#1B2A2A]/10 bg-white px-3 py-2.5">
                                            <span class="text-sm font-semibold">{supplement.name}</span>
                                            <span class="shrink-0 text-xs font-semibold text-[#127A79]">{supplementTypeLabel(supplement.supplement_type)}</span>
                                        </div>
                                    {/each}
                                </div>
                            </section>
                        {/if}
                    </div>
                </div>
            </div>
        {/if}
    </div>
</AdminLayout>
