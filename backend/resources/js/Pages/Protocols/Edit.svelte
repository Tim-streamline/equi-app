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
    const initialPhases = (initialProtocol?.phases?.length ? initialProtocol.phases : [
        {
            title: 'Fase 1',
            state: 'active',
            week_start: 1,
            week_end: 4,
            chip_label: 'Actief',
            items: [],
        },
        {
            title: 'Fase 2',
            state: 'upcoming',
            week_start: 5,
            week_end: 6,
            chip_label: 'Binnenkort',
            items: [],
        },
        {
            title: 'Fase 3',
            state: 'upcoming',
            week_start: 7,
            week_end: 8,
            chip_label: 'Binnenkort',
            items: [],
        },
    ]).map((phase) => ({
        id: phase.id ?? null,
        client_key: phase.id ?? makeClientKey(),
        title: phase.title ?? '',
        state: phase.state ?? 'upcoming',
        week_start: phase.week_start ?? '',
        week_end: phase.week_end ?? '',
        chip_label: phase.chip_label ?? '',
        items: (phase.items ?? []).map((item) => ({ id: item.id ?? null, label: item.label ?? '' })),
    }));

    const form = useForm({
        horse_id: initialProtocol?.horse_id ?? initialSelectedHorseId ?? '',
        protocol_type_id: initialProtocol?.protocol_type_id ?? initialProtocolTypes[0]?.id ?? '',
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

    let activePhaseKey = $state(initialPhases[0].client_key);
    let unmatchedMeasuresOpen = $state(false);
    let overviewOpen = $state(false);

    const measureLibrary = [
        { id: 'psyllium', name: 'Psylliumzaad', dose: '175 g · 2× daags · 6 weken', tags: ['darmgezondheid', 'overgewicht'], phases: [1] },
        { id: 'heemst', name: 'Heemstwortel', dose: '20 g · 6 weken', tags: ['darmgezondheid'], phases: [1] },
        { id: 'kamille', name: 'Kamille', dose: '25 g · 6 weken', tags: ['darmgezondheid', 'stress'], phases: [1] },
        { id: 'msm', name: 'MSM (zwavel)', dose: 'Dosering leverancier · 8 weken', tags: ['pezen'], phases: [1, 2] },
        { id: 'zink', name: 'Zink', dose: '40 g · 8 weken', tags: ['jeuk', 'pezen'], phases: [1, 2] },
        { id: 'lapacho', name: 'Lapachoschors', dose: '20 g · 6 weken', tags: ['darmgezondheid'], phases: [2] },
        { id: 'weegbree', name: 'Smalle weegbree', dose: '25 g · 6 weken', tags: ['darmgezondheid'], phases: [2] },
        { id: 'brandnetel', name: 'Brandnetel', dose: '25 g · 6 weken', tags: ['jeuk', 'overgewicht'], phases: [2] },
        { id: 'boswellia', name: 'Boswellia Serrata', dose: '6 g per dag', tags: ['maagklachten'], phases: [1] },
        { id: 'gastercare', name: 'GasterCare forte', dose: '2–3× daags · 3 maanden', tags: ['maagklachten'], phases: [1, 2, 3] },
        { id: 'lapachovervolg', name: 'Lapachoschors, onderhoud', dose: '10 g · 4 weken', tags: ['darmgezondheid'], phases: [3] },
    ];
    const tagAliases = {
        darmgezondheid: ['darm', 'gut'],
        overgewicht: ['overgewicht', 'gewicht', 'weight'],
        jeuk: ['jeuk', 'itch'],
        pezen: ['pees', 'pezen', 'tendon'],
        stress: ['stress'],
        maagklachten: ['maag', 'stomach'],
    };
    const tagStyles = {
        darmgezondheid: 'bg-[#EAFBF9] text-[#0E6F69]',
        overgewicht: 'bg-[#FDF4E4] text-[#7A5A16]',
        jeuk: 'bg-[#FBEAF0] text-[#A03D63]',
        pezen: 'bg-[#EAF0FB] text-[#33538F]',
        stress: 'bg-[#F1EAFB] text-[#5B3FA0]',
        maagklachten: 'bg-[#FDEDE7] text-[#A8442F]',
    };

    const selectedHorse = $derived(horses.find((horse) => horse.id === $form.horse_id));
    const activePhaseIndex = $derived(Math.max(0, $form.phases.findIndex((phase) => phase.client_key === activePhaseKey)));
    const activePhase = $derived($form.phases[activePhaseIndex]);
    const activePhaseTasks = $derived($form.tasks.filter((task) => task.phase_key === activePhaseKey));
    const horseTopicTokens = $derived((selectedHorse?.focus_topics ?? [])
        .flatMap((topic) => [topic.slug, topic.title])
        .filter(Boolean)
        .map((topic) => topic.toLowerCase()));
    const availableMeasures = $derived(measureLibrary.filter((measure) => measure.phases.includes(activePhaseIndex + 1)));
    const matchedMeasures = $derived(availableMeasures.filter((measure) => measureMatchesHorse(measure)));
    const unmatchedMeasures = $derived(availableMeasures.filter((measure) => !measureMatchesHorse(measure)));
    const customMeasureRows = $derived($form.tasks
        .map((task, index) => ({ task, index }))
        .filter(({ task }) => task.phase_key === activePhaseKey)
        .filter(({ task }) => !availableMeasures.some((measure) => measure.name === task.label)));

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
    function addPhaseItem() {
        $form.phases[activePhaseIndex].items = [
            ...$form.phases[activePhaseIndex].items,
            { id: null, label: '' },
        ];
    }

    function removePhaseItem(itemIndex) {
        $form.phases[activePhaseIndex].items = $form.phases[activePhaseIndex].items.filter((_, index) => index !== itemIndex);
    }

    function addMeasure() {
        $form.tasks = [...$form.tasks, {
            id: null,
            phase_key: activePhaseKey,
            label: '',
            meta: '',
            kind: 'feeding',
            active_from: '',
            active_until: '',
            reference_item_id: '',
        }];
    }

    function measureMatchesHorse(measure) {
        return measure.tags.some((tag) => (tagAliases[tag] ?? [tag])
            .some((alias) => horseTopicTokens.some((topic) => topic.includes(alias))));
    }

    function measureTaskIndex(measure) {
        return $form.tasks.findIndex((task) => task.phase_key === activePhaseKey && task.label === measure.name);
    }

    function toggleMeasure(measure) {
        const taskIndex = measureTaskIndex(measure);
        if (taskIndex >= 0) {
            removeTask(taskIndex);
            return;
        }

        $form.tasks = [...$form.tasks, {
            id: null,
            phase_key: activePhaseKey,
            label: measure.name,
            meta: measure.dose,
            kind: 'feeding',
            active_from: '',
            active_until: '',
            reference_item_id: '',
        }];
    }

    function removeTask(index) {
        $form.tasks = $form.tasks.filter((_, taskIndex) => taskIndex !== index);
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
                    <Eye class="size-4" /> Overview ({activePhaseTasks.length})
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
                                <Select bind:value={$form.protocol_type_id} placeholder="Select a protocol type" options={protocolTypeOptions} />
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
                        <div class="mb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-[#1B2A2A]/45">Protocol phase</div>
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
                                </button>
                            {/each}
                        </div>
                        {#if $form.errors.phases}<p class="mt-1 text-xs text-destructive">{$form.errors.phases}</p>{/if}
                    </section>

                    {#if activePhase}
                        <section class="rounded-[20px] border border-[#1B2A2A]/10 bg-white p-5 md:p-6">
                            <div class="mb-5">
                                <h2 class="text-lg font-bold">Phase details</h2>
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
                                    <p class="mt-0.5 text-xs text-[#1B2A2A]/50">Maatregelen voor {activePhase.title || 'deze fase'}.</p>
                                </div>
                                <span class="shrink-0 text-xs font-semibold text-[#1B2A2A]/50">{activePhaseTasks.length} van {availableMeasures.length + customMeasureRows.length} actief</span>
                            </div>

                            <div class="divide-y divide-[#1B2A2A]/8">
                                {#each matchedMeasures as measure (measure.id)}
                                    {@const isSelected = measureTaskIndex(measure) >= 0}
                                    <div class={`flex items-start gap-3.5 px-5 py-4 transition-colors md:px-6 ${isSelected ? 'bg-white' : 'bg-[#FBF8F3]/55'}`}>
                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked={isSelected}
                                            aria-label={`${isSelected ? 'Remove' : 'Add'} ${measure.name}`}
                                            onclick={() => toggleMeasure(measure)}
                                            class={`relative mt-0.5 h-[23px] w-10 shrink-0 rounded-full transition-colors ${isSelected ? 'bg-[#18BAB0]' : 'bg-[#1B2A2A]/20'}`}
                                        >
                                            <span class={`absolute top-[2.5px] size-[18px] rounded-full bg-white shadow-sm transition-transform ${isSelected ? 'translate-x-[19px]' : 'translate-x-[2px]'}`}></span>
                                        </button>
                                        <div class={`min-w-0 flex-1 transition-opacity ${isSelected ? '' : 'opacity-60'}`}>
                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <span class="text-sm font-bold">{measure.name}</span>
                                                <span class="rounded-full bg-[#EAFBF9] px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.08em] text-[#0E6F69]">Voorgesteld</span>
                                            </div>
                                            <p class="mt-1 text-xs text-[#1B2A2A]/55">{measure.dose}</p>
                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                {#each measure.tags as tag (tag)}
                                                    <span class={`rounded-full px-2 py-0.5 text-[10px] ${tagStyles[tag] ?? 'bg-[#F4EFE7] text-[#5A5A5A]'}`}>{tag}</span>
                                                {/each}
                                            </div>
                                        </div>
                                    </div>
                                {/each}

                                {#if unmatchedMeasures.length}
                                    <button
                                        type="button"
                                        onclick={() => (unmatchedMeasuresOpen = !unmatchedMeasuresOpen)}
                                        class="flex w-full items-center gap-1.5 px-5 py-3 text-left text-xs font-bold text-[#108A82] transition hover:bg-[#EAFBF9]/55 md:px-6"
                                        aria-expanded={unmatchedMeasuresOpen}
                                    >
                                        <ChevronRight class={`size-3.5 transition-transform ${unmatchedMeasuresOpen ? 'rotate-90' : ''}`} />
                                        {unmatchedMeasuresOpen ? 'Verberg niet-gematchte opties' : `+${unmatchedMeasures.length} niet-gematchte opties tonen`}
                                    </button>

                                    {#if unmatchedMeasuresOpen}
                                        {#each unmatchedMeasures as measure (measure.id)}
                                            {@const isSelected = measureTaskIndex(measure) >= 0}
                                            <div class="flex items-start gap-3.5 bg-[#FBF8F3]/55 px-5 py-4 md:px-6">
                                                <button
                                                    type="button"
                                                    role="switch"
                                                    aria-checked={isSelected}
                                                    aria-label={`${isSelected ? 'Remove' : 'Add'} ${measure.name}`}
                                                    onclick={() => toggleMeasure(measure)}
                                                    class={`relative mt-0.5 h-[23px] w-10 shrink-0 rounded-full transition-colors ${isSelected ? 'bg-[#18BAB0]' : 'bg-[#1B2A2A]/20'}`}
                                                >
                                                    <span class={`absolute top-[2.5px] size-[18px] rounded-full bg-white shadow-sm transition-transform ${isSelected ? 'translate-x-[19px]' : 'translate-x-[2px]'}`}></span>
                                                </button>
                                                <div class={`min-w-0 flex-1 ${isSelected ? '' : 'opacity-60'}`}>
                                                    <div class="text-sm font-bold">{measure.name}</div>
                                                    <p class="mt-1 text-xs text-[#1B2A2A]/55">{measure.dose}</p>
                                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                                        {#each measure.tags as tag (tag)}
                                                            <span class={`rounded-full px-2 py-0.5 text-[10px] ${tagStyles[tag] ?? 'bg-[#F4EFE7] text-[#5A5A5A]'}`}>{tag}</span>
                                                        {/each}
                                                    </div>
                                                </div>
                                            </div>
                                        {/each}
                                    {/if}
                                {/if}

                                {#each customMeasureRows as row (row.task.id ?? row.index)}
                                    <div class="bg-[#FBF8F3] px-5 py-4 md:px-6">
                                        <div class="flex items-start gap-3">
                                            <div class="grid min-w-0 flex-1 gap-3 sm:grid-cols-2">
                                                <Field label="Naam" error={errorFor(`tasks.${row.index}.label`)}><Input bind:value={$form.tasks[row.index].label} placeholder="Naam van de maatregel" /></Field>
                                                <Field label="Dosering of omschrijving" error={errorFor(`tasks.${row.index}.meta`)}><Input bind:value={$form.tasks[row.index].meta} placeholder="Bijv. 20 g · 6 weken" /></Field>
                                            </div>
                                            <div class="pt-[26px]"><Button type="button" variant="ghost" size="icon" onclick={() => removeTask(row.index)} aria-label="Verwijder maatregel"><Trash2 class="size-4 text-destructive" /></Button></div>
                                        </div>
                                    </div>
                                {/each}

                                <button type="button" onclick={addMeasure} class="flex w-full items-center gap-1.5 px-5 py-3.5 text-left text-sm font-bold text-[#108A82] transition hover:bg-[#EAFBF9]/55 md:px-6">
                                    <Plus class="size-4" /> Voeg maatregel toe
                                </button>
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

                        {#if activePhaseTasks.length}
                            <section>
                                <div class="mb-2 text-[11px] font-bold uppercase tracking-[0.1em] text-[#108A82]">Maatregelen</div>
                                <div class="space-y-2">
                                    {#each activePhaseTasks as task (task.id ?? task.label)}
                                        <div class="flex items-start justify-between gap-3 rounded-xl border border-[#1B2A2A]/10 bg-white px-3 py-2.5">
                                            <span class="text-sm font-semibold">{task.label || 'Naamloze maatregel'}</span>
                                            {#if task.meta}<span class="shrink-0 text-xs font-semibold text-[#127A79]">{task.meta}</span>{/if}
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
