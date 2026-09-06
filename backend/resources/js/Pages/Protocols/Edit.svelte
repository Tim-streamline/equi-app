<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import Field from '$lib/components/Field.svelte';
    import {
        defaultProtocolSupplementDosage,
        defaultProtocolSupplementInstructions,
        templateDosage,
    } from '$lib/protocolDosage.js';
    import { protocolPhaseRanges, totalProtocolWeeks } from '$lib/protocolPhasePlanning.js';
    import { Link, useForm } from '@inertiajs/svelte';
    import { onMount, untrack } from 'svelte';
    import { Button, Input, Select, Textarea } from '$lib/components/ui';
    import {
        ArrowLeft,
        CalendarDays,
        Check,
        ChevronRight,
        CircleAlert,
        Clock3,
        Eye,
        FileText,
        Dumbbell,
        Leaf,
        Lock,
        Plus,
        Save,
        Send,
        Settings2,
        Trash2,
        UserRound,
        X,
    } from '@lucide/svelte';

    let {
        protocol,
        horses,
        therapists,
        protocolTemplates,
        voedingAdviezen,
        managementAdviezen,
        bewegingAdviezen,
        selectedHorseId = null,
        intakeFeeds = {},
        weeklyUpdates = [],
        libraryItems = [],
    } = $props();

    const initialProtocol = untrack(() => protocol);
    const initialProtocolTemplates = untrack(() => protocolTemplates);
    const initialHorses = untrack(() => horses);
    const initialSelectedHorseId = untrack(() => selectedHorseId);
    const isNew = !initialProtocol;
    let nextClientKey = 0;
    const makeClientKey = () => `phase-${Date.now()}-${nextClientKey++}`;

    function catalogFromTemplate(protocolTemplate) {
        if (!protocolTemplate) return null;

        return {
            protocol_template_id: protocolTemplate.id,
            name: protocolTemplate.name,
            phases: (protocolTemplate.phases ?? []).map((phase) => ({
                id: phase.id,
                name: phase.name,
                description: phase.description,
                required: phase.required,
                order: phase.order,
                start_after_previous_phase_weeks: phase.start_after_previous_phase_weeks,
                weeks: (phase.weeks ?? []).map((week) => ({ id: week.id, number: week.number })),
                supplements: (phase.supplements ?? []).map((supplement) => ({
                    id: supplement.id,
                    name: supplement.name,
                    description: supplement.description,
                    instructions: supplement.instructions,
                    supplement_type: supplement.supplement_type,
                    dosis_type: supplement.dosis_type,
                    dosis: supplement.dosis,
                    unit: supplement.unit,
                    add_by_default: supplement.add_by_default,
                    max_aantal_in_fase: supplement.max_aantal_in_fase,
                    min_aantal_per_week: supplement.min_aantal_per_week,
                    rust_periode_in_weken: supplement.rust_periode_in_weken,
                    week_numbers: (supplement.weeks ?? []).map((week) => week.number),
                })),
            })),
        };
    }

    function selectionFromSupplement(supplement, weekCount, horseWeightKg) {
        return {
            id: null,
            supplement_id: supplement.id,
            name: supplement.name,
            description: supplement.description,
            supplement_type: supplement.supplement_type,
            dosis_type: supplement.dosis_type,
            dosis: supplement.dosis,
            unit: supplement.unit,
            add_by_default: supplement.add_by_default,
            max_aantal_in_fase: supplement.max_aantal_in_fase,
            min_aantal_per_week: supplement.min_aantal_per_week,
            rust_periode_in_weken: supplement.rust_periode_in_weken,
            dosage: templateDosage(supplement, horseWeightKg),
            aantal_per_week: supplement.min_aantal_per_week ?? 0,
            instructions: supplement.instructions ?? '',
            week_numbers: (supplement.week_numbers ?? []).filter((number) => number <= weekCount),
        };
    }

    function phaseFromDefinition(definition, horseWeightKg) {
        const weekCount = definition.weeks?.length ?? 0;

        return {
            id: null,
            client_key: makeClientKey(),
            protocol_template_phase_id: definition.id,
            title: definition.name,
            description: definition.description,
            required: definition.required,
            start_after_previous_phase_weeks: definition.start_after_previous_phase_weeks,
            week_count: weekCount,
            supplements: (definition.supplements ?? [])
                .filter((supplement) => supplement.add_by_default)
                .map((supplement) => selectionFromSupplement(supplement, weekCount, horseWeightKg)),
        };
    }

    const initialProtocolTemplateId = initialProtocol?.protocol_template_id ?? initialProtocolTemplates[0]?.id ?? '';
    const initialCurrentTemplate = initialProtocolTemplates.find((template) => template.id === initialProtocolTemplateId);
    const initialHorse = initialHorses.find((horse) => horse.id === (initialProtocol?.horse_id ?? initialSelectedHorseId));
    const initialTemplate = catalogFromTemplate(initialCurrentTemplate);
    const initialPhaseRows = initialProtocol?.phases?.length
        ? initialProtocol.phases
        : (initialTemplate?.phases ?? [])
            .filter((phase) => phase.required)
            .map((phase) => phaseFromDefinition(phase, initialHorse?.weight_kg));
    const initialPhases = initialPhaseRows.map((phase) => {
        const definition = initialTemplate?.phases?.find(
            (candidate) => candidate.id === phase.protocol_template_phase_id,
        );

        return {
            id: phase.id ?? null,
            client_key: phase.client_key ?? phase.id ?? makeClientKey(),
            protocol_template_phase_id: phase.protocol_template_phase_id,
            title: phase.title ?? definition?.name ?? '',
            description: phase.description ?? definition?.description ?? '',
            required: phase.required ?? definition?.required ?? false,
            start_after_previous_phase_weeks: phase.start_after_previous_phase_weeks !== undefined
                ? phase.start_after_previous_phase_weeks
                : definition?.start_after_previous_phase_weeks ?? null,
            week_count: phase.week_count ?? phase.weeks?.length ?? 0,
            supplements: (phase.supplements ?? []).map((selection) => {
                const supplement = definition?.supplements?.find((candidate) => candidate.id === selection.supplement_id);

                return {
                    id: selection.id ?? null,
                    supplement_id: selection.supplement_id ?? null,
                    name: selection.name ?? selection.supplement?.name ?? '',
                    description: selection.description ?? selection.supplement?.description ?? '',
                    supplement_type: selection.supplement_type ?? selection.supplement?.supplement_type ?? 'supplement',
                    dosis_type: selection.dosis_type ?? supplement?.dosis_type ?? null,
                    dosis: selection.dosis ?? supplement?.dosis ?? null,
                    unit: selection.unit ?? supplement?.unit ?? null,
                    add_by_default: selection.add_by_default ?? supplement?.add_by_default ?? false,
                    max_aantal_in_fase: selection.max_aantal_in_fase ?? supplement?.max_aantal_in_fase ?? null,
                    min_aantal_per_week: selection.min_aantal_per_week ?? supplement?.min_aantal_per_week ?? 0,
                    rust_periode_in_weken: selection.rust_periode_in_weken ?? supplement?.rust_periode_in_weken ?? 0,
                    dosage: defaultProtocolSupplementDosage(selection.dosage, selection, initialHorse?.weight_kg),
                    aantal_per_week: selection.aantal_per_week ?? selection.supplement?.min_aantal_per_week ?? 0,
                    instructions: defaultProtocolSupplementInstructions(selection.instructions, selection),
                    week_numbers: (selection.week_numbers ?? selection.weeks?.map((week) => week.protocol_phase_week?.number) ?? [])
                        .filter(Boolean),
                };
            }),
        };
    });

    const protocolTemplateName = initialProtocol?.protocol_template_name ?? initialTemplate?.name;
    const defaultTitle = [protocolTemplateName, initialHorse?.name].filter(Boolean).join(' · ');

    function adviceCatalogWithSnapshots(settings, snapshots, sourceKey) {
        return settings.map((setting) => {
            const snapshot = (snapshots ?? []).find((candidate) => candidate[sourceKey] === setting.id);

            return snapshot
                ? { ...setting, title: snapshot.title, description: snapshot.description, snapshot: true }
                : { ...setting, snapshot: false };
        });
    }

    const form = useForm({
        horse_id: initialProtocol?.horse_id ?? initialSelectedHorseId ?? '',
        protocol_template_id: initialProtocolTemplateId,
        therapist_id: initialProtocol?.therapist_id ?? '',
        title: initialProtocol?.title ?? defaultTitle,
        started_at: initialProtocol?.started_at?.slice(0, 10) ?? '',
        status: initialProtocol?.status ?? 'paused',
        published: Boolean(initialProtocol?.published_at),
        customer_settings: {
            hay_library_item_id: initialProtocol?.customer_settings?.hay_library_item_id ?? '',
            water_library_item_id: initialProtocol?.customer_settings?.water_library_item_id ?? '',
            target_weight_kg: initialProtocol?.customer_settings?.target_weight_kg ?? '',
            sugar: initialProtocol?.customer_settings?.sugar ?? '<7%',
            protein: initialProtocol?.customer_settings?.protein ?? '6–9%',
            weekly_update_day: initialProtocol?.customer_settings?.weekly_update_day ?? 7,
            feed_overrides: initialProtocol?.customer_settings?.feed_overrides ?? [],
            management: initialProtocol?.customer_settings?.management ?? [],
        },
        analysis: {
            cause: initialProtocol?.analysis?.cause ?? '',
            summary: initialProtocol?.analysis?.summary ?? '',
            focus_points: initialProtocol?.analysis?.focus_points ?? [],
            observations: initialProtocol?.analysis?.observations ?? [],
        },
        advice: (initialProtocol?.analysis?.advice ?? []).map((row) => ({
            id: row.id ?? null,
            icon_key: row.icon_key ?? 'leaf',
            title: row.title ?? '',
            body: row.body ?? '',
        })),
        voeding_advies_ids: (initialProtocol?.voeding_adviezen ?? []).map((row) => row.voeding_advies_id),
        management_advies_ids: (initialProtocol?.management_adviezen ?? []).map((row) => row.management_advies_id),
        beweging_advies_ids: (initialProtocol?.beweging_adviezen ?? []).map((row) => row.beweging_advies_id),
        phases: initialPhases,
    });

    function feedSetting(feed) {
        return $form.customer_settings.feed_overrides.find((row) => row.id === feed.id) ?? feed;
    }
    function updateFeed(feed, key, value) {
        const rows = $form.customer_settings.feed_overrides;
        const current = rows.find((row) => row.id === feed.id) ?? { id: feed.id, status: feed.status, note: '' };
        $form.customer_settings.feed_overrides = [...rows.filter((row) => row.id !== feed.id), { ...current, [key]: value }];
    }
    function managementSetting(advice) {
        return $form.customer_settings.management.find((row) => row.id === advice.id) ?? {
            id: advice.id,
            category: /mestonderzoek|bloed|urine|onderzoek|monitor/i.test(advice.title) ? 'monitoring' : /hoef|gebit|tand|behandel|verzorg/i.test(advice.title) ? 'care' : 'environment',
            action: 'do', instruction: advice.description, note: '', frequency: '', url: '', cta_label: '',
        };
    }
    function updateManagement(advice, key, value) {
        const rows = $form.customer_settings.management;
        $form.customer_settings.management = [...rows.filter((row) => row.id !== advice.id), { ...managementSetting(advice), [key]: value }];
    }

    let workingTemplate = $state(initialTemplate);
    let activeSection = $state(isNew ? 'basis' : 'planning');
    let activePhaseKey = $state(initialPhases[0]?.client_key ?? null);
    let phaseToAddId = $state('');
    let overviewOpen = $state(false);
    let typeChangeDialogOpen = $state(false);
    let protocolTemplateChoice = $state(initialProtocolTemplateId);
    let pendingProtocolTemplateId = $state('');
    const displayedProtocolTemplateName = $derived(isNew
        ? workingTemplate?.name
        : initialProtocol?.protocol_template_name ?? workingTemplate?.name);

    const sections = [
        { id: 'basis', label: 'Basis', icon: UserRound },
        { id: 'planning', label: 'Planning', icon: CalendarDays },
        { id: 'voeding', label: 'Voeding', icon: Leaf, formKey: 'voeding_advies_ids' },
        { id: 'management', label: 'Management', icon: Settings2, formKey: 'management_advies_ids' },
        { id: 'beweging', label: 'Beweging', icon: Dumbbell, formKey: 'beweging_advies_ids' },
        { id: 'content', label: 'Analyse', icon: FileText },
        { id: 'preview', label: 'Klantweergave', icon: Eye },
    ];
    const horseOptions = $derived(horses.map((horse) => ({
        value: horse.id,
        label: `${horse.name}${horse.owner?.name ? ` · ${horse.owner.name}` : ''}`,
    })));
    const therapistOptions = $derived(therapists.map((therapist) => ({
        value: therapist.id,
        label: `${therapist.name}${therapist.title ? ` · ${therapist.title}` : ''}`,
    })));
    const protocolTemplateOptions = $derived(protocolTemplates.map((protocolTemplate) => ({
        value: protocolTemplate.id,
        label: protocolTemplate.name,
    })));
    const statusOptions = [
        { value: 'paused', label: 'Gepauzeerd' },
        { value: 'active', label: 'Actief' },
        { value: 'completed', label: 'Afgerond' },
    ];
    const selectedHorse = $derived(horses.find((horse) => horse.id === $form.horse_id));
    const activePhaseIndex = $derived($form.phases.findIndex((phase) => phase.client_key === activePhaseKey));
    const activePhase = $derived(activePhaseIndex >= 0 ? $form.phases[activePhaseIndex] : null);
    const activeDefinition = $derived(workingTemplate?.phases?.find(
        (phase) => phase.id === activePhase?.protocol_template_phase_id,
    ));
    const phaseRanges = $derived(protocolPhaseRanges($form.phases));
    const totalWeeks = $derived(totalProtocolWeeks($form.phases));
    const currentWeek = $derived(calculateCurrentWeek($form.started_at, $form.status, initialProtocol?.current_week, totalWeeks));
    const availablePhaseDefinitions = $derived((workingTemplate?.phases ?? [])
        .filter((definition) => !$form.phases.some((phase) => phase.protocol_template_phase_id === definition.id)));
    const availablePhaseOptions = $derived(availablePhaseDefinitions.map((definition) => ({
        value: definition.id,
        label: `${definition.name}${definition.required ? ' · verplicht' : ''}${definition.weeks.length ? ` · ${definition.weeks.length} wk` : ' · geen weken'}`,
    })));
    const allActiveSupplements = $derived($form.phases.flatMap((phase) => phase.supplements));
    const adviceCategories = $derived([
        {
            id: 'voeding',
            label: 'Voeding',
            entity: 'ProtocolVoedingAdvies',
            formKey: 'voeding_advies_ids',
            step: 3,
            description: 'Selecteer de voedingsadviezen die bij dit protocol horen.',
            items: adviceCatalogWithSnapshots(voedingAdviezen, initialProtocol?.voeding_adviezen, 'voeding_advies_id'),
            next: 'management',
            nextLabel: 'Management',
        },
        {
            id: 'management',
            label: 'Management',
            entity: 'ProtocolManagementAdvies',
            formKey: 'management_advies_ids',
            step: 4,
            description: 'Selecteer adviezen over huisvesting, routine en herstel.',
            items: adviceCatalogWithSnapshots(managementAdviezen, initialProtocol?.management_adviezen, 'management_advies_id'),
            next: 'beweging',
            nextLabel: 'Beweging',
        },
        {
            id: 'beweging',
            label: 'Beweging',
            entity: 'ProtocolBewegingAdvies',
            formKey: 'beweging_advies_ids',
            step: 5,
            description: 'Selecteer adviezen over training, opbouw en bewegingsvrijheid.',
            items: adviceCatalogWithSnapshots(bewegingAdviezen, initialProtocol?.beweging_adviezen, 'beweging_advies_id'),
            next: 'content',
            nextLabel: 'Analyse',
        },
    ]);
    const activeAdviceCategory = $derived(adviceCategories.find((category) => category.id === activeSection) ?? null);
    const selectedProtocolAdviceCount = $derived(adviceCategories.reduce(
        (total, category) => total + $form[category.formKey].length,
        0,
    ));

    onMount(() => {
        const warnBeforeUnload = (event) => {
            if (!$form.isDirty) return;
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warnBeforeUnload);

        return () => window.removeEventListener('beforeunload', warnBeforeUnload);
    });

    function calculateCurrentWeek(startDate, status, storedCurrentWeek, weeks) {
        if (!weeks) return 0;
        if (status === 'completed') return weeks;
        if (status === 'paused' || !startDate) return Math.max(1, Math.min(weeks, Number(storedCurrentWeek || 1)));
        const start = new Date(`${startDate}T00:00:00`);
        const today = new Date();
        const days = Math.max(0, Math.floor((today.setHours(0, 0, 0, 0) - start.getTime()) / 86400000));

        return Math.max(1, Math.min(weeks, Math.floor(days / 7) + 1));
    }

    function sortPhasesByTemplate(phases) {
        const templateOrder = new Map((workingTemplate?.phases ?? []).map((phase, index) => [phase.id, index]));

        return [...phases].sort((left, right) => (
            (templateOrder.get(left.protocol_template_phase_id) ?? Number.MAX_SAFE_INTEGER)
            - (templateOrder.get(right.protocol_template_phase_id) ?? Number.MAX_SAFE_INTEGER)
        ));
    }

    function isRequiredPhase(phase) {
        return phase?.required ?? false;
    }

    function phaseRange(index) {
        return phaseRanges[index] ?? { start: null, end: null };
    }

    function phaseState(index) {
        const range = phaseRange(index);
        if (!range.start) return 'Geen weken';
        if ($form.status === 'completed' || range.end < currentWeek) return 'Afgerond';
        if (range.start <= currentWeek && range.end >= currentWeek) return $form.status === 'paused' ? 'Gepauzeerd' : 'Actief';
        return 'Gepland';
    }

    function selectPhase(phase) {
        activePhaseKey = phase.client_key;
    }

    function requestProtocolTemplateChange() {
        if (protocolTemplateChoice === $form.protocol_template_id) return;
        pendingProtocolTemplateId = protocolTemplateChoice;
        typeChangeDialogOpen = true;
    }

    function cancelProtocolTemplateChange() {
        protocolTemplateChoice = $form.protocol_template_id;
        pendingProtocolTemplateId = '';
        typeChangeDialogOpen = false;
    }

    function applyProtocolTemplateChange() {
        const protocolTemplate = protocolTemplates.find((type) => type.id === pendingProtocolTemplateId);
        workingTemplate = catalogFromTemplate(protocolTemplate);
        $form.protocol_template_id = pendingProtocolTemplateId;
        $form.phases = (workingTemplate?.phases ?? [])
            .filter((phase) => phase.required)
            .map((phase) => phaseFromDefinition(phase, selectedHorse?.weight_kg));
        $form.title = [workingTemplate?.name, selectedHorse?.name].filter(Boolean).join(' · ');
        activePhaseKey = $form.phases[0]?.client_key ?? null;
        phaseToAddId = '';
        pendingProtocolTemplateId = '';
        typeChangeDialogOpen = false;
    }

    function updateSuggestedTitle() {
        if (!$form.title || $form.title === defaultTitle) {
            $form.title = [workingTemplate?.name, selectedHorse?.name].filter(Boolean).join(' · ');
        }

        recalculateHorseDosages();
    }

    function recalculateHorseDosages() {
        $form.phases = $form.phases.map((phase) => {
            return {
                ...phase,
                supplements: phase.supplements.map((selection) => {
                    if (!['per_kg', 'per_600_kg'].includes(selection.dosis_type)) return selection;

                    return {
                        ...selection,
                        dosage: templateDosage(selection, selectedHorse?.weight_kg),
                    };
                }),
            };
        });
    }

    function addPhase() {
        const definition = availablePhaseDefinitions.find((phase) => phase.id === phaseToAddId);
        if (!definition) return;
        const phase = phaseFromDefinition(definition, selectedHorse?.weight_kg);
        $form.phases = sortPhasesByTemplate([...$form.phases, phase]);
        activePhaseKey = phase.client_key;
        phaseToAddId = '';
    }

    function removeActivePhase() {
        if (!activePhase || isRequiredPhase(activePhase)) return;
        const removedIndex = activePhaseIndex;
        const remaining = $form.phases.filter((phase) => phase.client_key !== activePhase.client_key);
        $form.phases = remaining;
        activePhaseKey = remaining[Math.min(removedIndex, remaining.length - 1)]?.client_key ?? null;
    }

    function normalizeActivePhaseWeeks() {
        const weekCount = Math.max(0, Number($form.phases[activePhaseIndex].week_count || 0));
        $form.phases = $form.phases.map((phase, index) => index === activePhaseIndex
            ? {
                ...phase,
                week_count: weekCount,
                supplements: phase.supplements.map((selection) => ({
                    ...selection,
                    week_numbers: selection.week_numbers.filter((number) => number <= weekCount),
                })),
            }
            : phase);
    }

    function toggleActivePhaseStartDelay() {
        if (activePhaseIndex < 0) return;

        $form.phases = $form.phases.map((phase, index) => index === activePhaseIndex
            ? {
                ...phase,
                start_after_previous_phase_weeks: phase.start_after_previous_phase_weeks === null ? 1 : null,
            }
            : phase);
    }

    function normalizeActivePhaseStartDelay() {
        if (activePhaseIndex < 0) return;
        const enteredValue = $form.phases[activePhaseIndex].start_after_previous_phase_weeks;
        const weeks = Math.max(0, Math.min(104, Number(enteredValue === '' ? 1 : enteredValue ?? 1)));

        $form.phases = $form.phases.map((phase, index) => index === activePhaseIndex
            ? { ...phase, start_after_previous_phase_weeks: weeks }
            : phase);
    }

    function visibleSupplements() {
        const catalog = activeDefinition?.supplements ?? [];
        const catalogIds = new Set(catalog.map((supplement) => supplement.id));
        const archived = (activePhase?.supplements ?? [])
            .filter((selection) => !selection.supplement_id || !catalogIds.has(selection.supplement_id))
            .map((selection) => ({
                id: selection.supplement_id,
                archived_selection_id: selection.id,
                name: selection.name,
                description: selection.description,
                supplement_type: selection.supplement_type,
                week_numbers: selection.week_numbers,
                archived: true,
            }));

        return [...catalog, ...archived];
    }

    function selectedSupplementIndex(supplement) {
        return activePhase?.supplements?.findIndex((selection) => supplement.archived
            ? selection.id === supplement.archived_selection_id
            : selection.supplement_id === supplement.id) ?? -1;
    }

    function toggleSupplement(supplement) {
        if (!activePhase) return;
        const selectionIndex = selectedSupplementIndex(supplement);
        const supplements = selectionIndex >= 0
            ? activePhase.supplements.filter((_, index) => index !== selectionIndex)
            : [...activePhase.supplements, selectionFromSupplement(
                supplement,
                Number(activePhase.week_count || 0),
                selectedHorse?.weight_kg,
            )];
        $form.phases = $form.phases.map((phase, index) => index === activePhaseIndex
            ? { ...phase, supplements }
            : phase);
    }

    function toggleSupplementWeek(selectionIndex, weekNumber) {
        const selection = activePhase.supplements[selectionIndex];
        const weekNumbers = selection.week_numbers.includes(weekNumber)
            ? selection.week_numbers.filter((number) => number !== weekNumber)
            : [...selection.week_numbers, weekNumber].sort((a, b) => a - b);
        const supplements = activePhase.supplements.map((row, index) => index === selectionIndex
            ? { ...row, week_numbers: weekNumbers }
            : row);
        $form.phases = $form.phases.map((phase, index) => index === activePhaseIndex
            ? { ...phase, supplements }
            : phase);
    }

    function supplementTypeLabel(type) {
        return ({ kruid: 'Kruid', mineraal: 'Mineraal', supplement: 'Supplement' })[type] ?? type;
    }

    function supplementWarning(supplement, selection) {
        if (!selection.week_numbers.length) return 'Selecteer minimaal één week.';
        const totalAdministrations = Number(selection.aantal_per_week || 0) * selection.week_numbers.length;
        if (supplement.max_aantal_in_fase !== null && totalAdministrations > supplement.max_aantal_in_fase) {
            return `Maximum ${supplement.max_aantal_in_fase} toedieningen in deze fase.`;
        }
        if (Number(selection.aantal_per_week || 0) < Number(supplement.min_aantal_per_week || 0)) {
            return `Minimaal ${supplement.min_aantal_per_week}× per week.`;
        }

        return null;
    }

    function addFocusPoint() {
        if ($form.analysis.focus_points.length >= 4) return;
        $form.analysis.focus_points = [...$form.analysis.focus_points, { title: '', body: '' }];
    }

    function addObservation() {
        if ($form.analysis.observations.length >= 4) return;
        $form.analysis.observations = [...$form.analysis.observations, ''];
    }

    function isProtocolAdviceSelected(category, adviceId) {
        return $form[category.formKey].includes(adviceId);
    }

    function toggleProtocolAdvice(category, adviceId) {
        const selectedIds = $form[category.formKey];
        $form[category.formKey] = selectedIds.includes(adviceId)
            ? selectedIds.filter((id) => id !== adviceId)
            : [...selectedIds, adviceId];
    }

    function selectedProtocolAdvice(category) {
        return category.items.filter((advice) => isProtocolAdviceSelected(category, advice.id));
    }

    function save(published = $form.published) {
        $form.published = published;
        if (isNew) $form.post('/admin/protocols');
        else $form.put(`/admin/protocols/${protocol.id}`);
    }

    function submit(event) {
        event.preventDefault();
        save();
    }

    function errorFor(path) {
        return $form.errors[path];
    }

    function sexLabel(value) {
        return ({ merrie: 'merrie', ruin: 'ruin', hengst: 'hengst' })[value] ?? value;
    }
</script>

<AdminLayout title={isNew ? 'Nieuw protocol' : 'Protocol bewerken'}>
    <div class="-mx-4 -my-6 min-h-[calc(100vh-4rem)] bg-[#FBF8F3] text-[#1B2A2A] lg:-mx-8">
        <form onsubmit={submit}>
            <header class="sticky top-16 z-20 border-b border-[#1B2A2A]/10 bg-white/95 backdrop-blur">
                <div class="flex min-h-16 flex-wrap items-center gap-3 px-4 py-3 lg:px-8">
                    <Link href="/admin/protocols" class="inline-flex items-center gap-1.5 text-sm font-semibold text-[#127A79] hover:text-[#0D5C5B]">
                        <ArrowLeft class="size-4" /> Protocollen
                    </Link>
                    <div class="h-5 w-px bg-[#1B2A2A]/10"></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate text-sm font-bold">{$form.title || (isNew ? 'Nieuw protocol' : 'Naamloos protocol')}</span>
                            <span class={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.08em] ${$form.published ? 'bg-[#EAFBF9] text-[#0E6F69]' : 'bg-[#F4EFE7] text-[#6B6258]'}`}>
                                {$form.published ? 'Gepubliceerd' : 'Concept'}
                            </span>
                            {#if $form.isDirty}<span class="text-[11px] font-semibold text-[#A06A1B]">Niet opgeslagen</span>{/if}
                        </div>
                        <div class="mt-0.5 truncate text-xs text-[#1B2A2A]/45">
                            {displayedProtocolTemplateName ?? 'Kies een protocoltemplate'}
                        </div>
                    </div>
                    <Button type="button" variant="outline" class="rounded-full" onclick={() => (overviewOpen = true)} disabled={!$form.phases.length}>
                        <Eye class="size-4" /> Volledige preview
                    </Button>
                    <Button type="submit" variant="outline" class="rounded-full" disabled={$form.processing}>
                        <Save class="size-4" /> Opslaan
                    </Button>
                    {#if !$form.published}
                        <Button type="button" class="rounded-full bg-[#18BAB0] px-5 hover:bg-[#108A82]" onclick={() => save(true)} disabled={$form.processing}>
                            <Send class="size-4" /> Publiceren
                        </Button>
                    {:else}
                        <Button type="button" variant="outline" class="rounded-full" onclick={() => save(false)} disabled={$form.processing}>
                            Depubliceren
                        </Button>
                    {/if}
                </div>
                <nav class="flex gap-1 overflow-x-auto px-4 lg:px-8" aria-label="Protocolonderdelen">
                    {#each sections as section (section.id)}
                        {@const SectionIcon = section.icon}
                        <button
                            type="button"
                            onclick={() => (activeSection = section.id)}
                            class={`relative inline-flex shrink-0 items-center gap-2 px-3 py-3 text-sm font-semibold transition ${activeSection === section.id ? 'text-[#0E6F69]' : 'text-[#1B2A2A]/50 hover:text-[#1B2A2A]'}`}
                        >
                            <SectionIcon class="size-4" /> {section.label}
                            {#if section.formKey}
                                <span class={`min-w-5 rounded-full px-1.5 py-0.5 text-center text-[10px] ${activeSection === section.id ? 'bg-[#EAFBF9] text-[#0E6F69]' : 'bg-[#1B2A2A]/5 text-[#1B2A2A]/45'}`}>
                                    {$form[section.formKey].length}
                                </span>
                            {/if}
                            {#if activeSection === section.id}<span class="absolute inset-x-2 bottom-0 h-0.5 rounded-full bg-[#18BAB0]"></span>{/if}
                        </button>
                    {/each}
                </nav>
            </header>

            {#if Object.keys($form.errors).length}
                <div class="mx-auto mt-5 max-w-[1180px] px-4 lg:px-8">
                    <div class="flex items-start gap-3 rounded-2xl border border-destructive/25 bg-destructive/8 px-4 py-3 text-sm text-destructive">
                        <CircleAlert class="mt-0.5 size-4 shrink-0" /> Controleer de gemarkeerde velden voordat je verdergaat.
                    </div>
                </div>
            {/if}

            <div class="mx-auto grid max-w-[1180px] gap-7 px-4 py-7 lg:grid-cols-[260px_minmax(0,1fr)] lg:px-8">
                <aside class="space-y-5 lg:sticky lg:top-44 lg:self-start">
                    <div>
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-[0.14em] text-[#108A82]">Paard</div>
                        {#if isNew}
                            <Select bind:value={$form.horse_id} placeholder="Kies een paard" options={horseOptions} onchange={updateSuggestedTitle} />
                            {#if $form.errors.horse_id}<p class="mt-1 text-xs text-destructive">{$form.errors.horse_id}</p>{/if}
                        {:else}
                            <div class="text-base font-bold">{selectedHorse?.name}</div>
                            <div class="mt-1 text-xs leading-5 text-[#1B2A2A]/50">
                                {[selectedHorse?.breed, sexLabel(selectedHorse?.sex), selectedHorse?.age ? `${selectedHorse.age} jaar` : null, selectedHorse?.weight_kg ? `${selectedHorse.weight_kg} kg` : null].filter(Boolean).join(' · ')}
                            </div>
                            {#if selectedHorse?.owner}<div class="mt-2 text-xs text-[#1B2A2A]/60">Eigenaar: <strong>{selectedHorse.owner.name}</strong></div>{/if}
                        {/if}
                    </div>

                    <div class="border-t border-[#1B2A2A]/10 pt-5">
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-[0.14em] text-[#108A82]">Plan</div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-[#1B2A2A]/50">Fases</dt><dd class="font-bold">{$form.phases.length}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-[#1B2A2A]/50">Duur</dt><dd class="font-bold">{totalWeeks} weken</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-[#1B2A2A]/50">Huidige week</dt><dd class="font-bold">{currentWeek || '—'}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-[#1B2A2A]/50">Supplementen</dt><dd class="font-bold">{allActiveSupplements.length}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-[#1B2A2A]/50">Adviezen</dt><dd class="font-bold">{selectedProtocolAdviceCount}</dd></div>
                        </dl>
                    </div>

                </aside>

                <main class="min-w-0">
                    {#if activeSection === 'basis'}
                        <section class="rounded-[22px] border border-[#1B2A2A]/10 bg-white p-5 md:p-7">
                            <div class="mb-7 max-w-xl">
                                <div class="text-[10px] font-bold uppercase tracking-[0.14em] text-[#108A82]">Stap 1</div>
                                <h2 class="mt-1 text-xl font-bold">Protocolbasis</h2>
                                <p class="mt-1 text-sm text-[#1B2A2A]/50">Kies de template en leg de patiëntspecifieke basis vast. Planning en voortgang worden automatisch berekend.</p>
                            </div>
                            <div class="grid gap-5 md:grid-cols-2">
                                <div>
                                    <Field label="Protocol template" error={$form.errors.protocol_template_id}>
                                        {#if isNew}
                                            <Select bind:value={protocolTemplateChoice} options={protocolTemplateOptions} onchange={requestProtocolTemplateChange} />
                                        {:else}
                                            <div class="flex h-10 items-center justify-between rounded-xl border border-[#1B2A2A]/10 bg-[#FBF8F3] px-3 text-sm">
                                                <span class="font-semibold">{displayedProtocolTemplateName}</span>
                                            </div>
                                        {/if}
                                    </Field>
                                    {#if !isNew}<p class="mt-1 text-xs text-[#1B2A2A]/40">De protocoltemplate staat vast na creatie.</p>{/if}
                                </div>
                                <Field label="Protocolnaam" error={$form.errors.title}><Input bind:value={$form.title} /></Field>
                                <Field label="Behandelaar" error={$form.errors.therapist_id}><Select bind:value={$form.therapist_id} placeholder="Geen behandelaar" options={therapistOptions} /></Field>
                                <Field label="Startdatum" error={$form.errors.started_at}><Input type="date" bind:value={$form.started_at} /></Field>
                                <Field label="Levenscyclus" error={$form.errors.status}><Select bind:value={$form.status} options={statusOptions} /></Field>
                                <div class="rounded-2xl bg-[#EAFBF9] p-4 text-sm text-[#0E6F69]">
                                    <div class="font-bold">Automatisch berekend</div>
                                    <div class="mt-1 text-xs leading-5">{totalWeeks} weken · huidige week {currentWeek || '—'} · fase-statussen en klantlabels volgen uit de planning.</div>
                                </div>
                            </div>
                            <div class="mt-7 flex justify-end">
                                <Button type="button" class="rounded-full bg-[#18BAB0] hover:bg-[#108A82]" onclick={() => (activeSection = 'planning')}>
                                    Naar planning <ChevronRight class="size-4" />
                                </Button>
                            </div>
                        </section>
                    {:else if activeSection === 'planning'}
                        <section>
                            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                                <div>
                                    <div class="text-[10px] font-bold uppercase tracking-[0.14em] text-[#108A82]">Stap 2</div>
                                    <h2 class="mt-1 text-xl font-bold">Planning</h2>
                                    <p class="mt-1 text-sm text-[#1B2A2A]/50">Fases volgen de templatevolgorde; een fase kan starten terwijl de vorige fase nog loopt.</p>
                                </div>
                                {#if availablePhaseOptions.length}
                                    <div class="flex items-center gap-2">
                                        <Select class="w-56" bind:value={phaseToAddId} placeholder="Optionele fase" options={availablePhaseOptions} />
                                        <Button type="button" variant="outline" size="sm" class="rounded-full bg-white" onclick={addPhase} disabled={!phaseToAddId}>
                                            <Plus class="size-4" /> Toevoegen
                                        </Button>
                                    </div>
                                {/if}
                            </div>

                            <div class="flex gap-2 overflow-x-auto pb-3">
                                {#each $form.phases as phase, index (phase.client_key)}
                                    {@const range = phaseRange(index)}
                                    <button
                                        type="button"
                                        onclick={() => selectPhase(phase)}
                                        class={`min-w-48 flex-1 rounded-2xl border px-4 py-3 text-left transition-all ${phase.client_key === activePhaseKey ? 'border-[#18BAB0] bg-[#EAFBF9] shadow-sm' : 'border-[#1B2A2A]/10 bg-white hover:-translate-y-0.5 hover:border-[#18BAB0]/45'}`}
                                    >
                                        <div class="flex items-start justify-between gap-2">
                                            <span class={`text-sm font-bold ${phase.client_key === activePhaseKey ? 'text-[#0E6F69]' : ''}`}>{phase.title}</span>
                                            {#if isRequiredPhase(phase)}<Lock class="mt-0.5 size-3.5 shrink-0 text-[#108A82]" />{/if}
                                        </div>
                                        <div class="mt-1 text-xs text-[#1B2A2A]/50">{range.start ? `Week ${range.start}–${range.end}` : 'Geen weken'} · {phaseState(index)}</div>
                                        {#if index > 0 && phase.start_after_previous_phase_weeks !== null}
                                            <div class="mt-1 text-[11px] font-semibold text-[#108A82]">Start na {phase.start_after_previous_phase_weeks} complete {phase.start_after_previous_phase_weeks === 1 ? 'week' : 'weken'} vorige fase</div>
                                        {/if}
                                    </button>
                                {:else}
                                    <div class="w-full rounded-2xl border border-dashed border-[#1B2A2A]/15 bg-white px-5 py-8 text-center text-sm text-[#1B2A2A]/45">Voeg een fase toe om de planning te starten.</div>
                                {/each}
                            </div>
                            {#if $form.errors.phases}<p class="mb-2 text-xs text-destructive">{$form.errors.phases}</p>{/if}

                            {#if activePhase}
                                {@const activeRange = phaseRange(activePhaseIndex)}
                                <div class="mt-2 overflow-hidden rounded-[22px] border border-[#1B2A2A]/10 bg-white">
                                    <div class="flex flex-wrap items-start gap-4 border-b border-[#1B2A2A]/10 px-5 py-5 md:px-7">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="text-lg font-bold">{activePhase.title}</h3>
                                                {#if isRequiredPhase(activePhase)}<span class="rounded-full bg-[#EAFBF9] px-2 py-0.5 text-[10px] font-bold uppercase text-[#0E6F69]">Verplicht</span>{/if}
                                            </div>
                                            <p class="mt-1 text-sm text-[#1B2A2A]/50">{activeRange.start ? `Week ${activeRange.start}–${activeRange.end}` : 'Nog geen weken'} · {phaseState(activePhaseIndex)}</p>
                                        </div>
                                        {#if !isRequiredPhase(activePhase)}
                                            <Button type="button" variant="ghost" size="sm" class="text-destructive hover:text-destructive" onclick={removeActivePhase}>
                                                <Trash2 class="size-4" /> Fase verwijderen
                                            </Button>
                                        {/if}
                                    </div>

                                    <div class="grid gap-6 px-5 py-6 md:grid-cols-[minmax(0,1fr)_220px] md:px-7">
                                        <div>
                                            <div class="mb-2 text-sm font-semibold">Templatefase</div>
                                            <div class="rounded-xl border border-[#1B2A2A]/10 bg-[#FBF8F3] px-3 py-2.5 text-sm font-semibold">{activePhase.title}</div>
                                            <p class="mt-1 text-xs text-[#1B2A2A]/40">De naam en beschrijving zijn bij het aanmaken vanuit de template overgenomen.</p>
                                        </div>
                                        <div>
                                            <Field label="Duur in weken" error={errorFor(`phases.${activePhaseIndex}.week_count`)}>
                                                <Input type="number" min="0" max="104" bind:value={$form.phases[activePhaseIndex].week_count} onchange={normalizeActivePhaseWeeks} />
                                            </Field>
                                            <p class="mt-1 text-xs text-[#1B2A2A]/40">Template: {activeDefinition?.weeks?.length ?? 0} weken</p>
                                        </div>
                                        {#if activePhaseIndex > 0}
                                            <div class="md:col-span-2 border-t border-[#1B2A2A]/10 pt-5">
                                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                                    <button type="button" class="flex max-w-xl items-start gap-3 text-left" onclick={toggleActivePhaseStartDelay}>
                                                        <span
                                                            role="switch"
                                                            aria-checked={activePhase.start_after_previous_phase_weeks !== null}
                                                            class={`relative mt-0.5 h-[23px] w-10 shrink-0 rounded-full transition-colors ${activePhase.start_after_previous_phase_weeks !== null ? 'bg-[#18BAB0]' : 'bg-[#1B2A2A]/20'}`}
                                                        ><span class={`absolute top-[2.5px] size-[18px] rounded-full bg-white shadow-sm transition-transform ${activePhase.start_after_previous_phase_weeks !== null ? 'translate-x-[19px]' : 'translate-x-[2px]'}`}></span></span>
                                                        <span>
                                                            <span class="block text-sm font-bold">Afwijkende start</span>
                                                            <span class="mt-0.5 block text-xs leading-5 text-[#1B2A2A]/45">Ingeschakeld start deze fase zodra het ingestelde aantal weken van de vorige fase compleet is. Gebruik 0 om tegelijk met de vorige fase te starten.</span>
                                                        </span>
                                                    </button>
                                                    {#if activePhase.start_after_previous_phase_weeks !== null}
                                                        <div class="w-full sm:w-56">
                                                            <Field label="Start na aantal complete weken vorige fase" error={errorFor(`phases.${activePhaseIndex}.start_after_previous_phase_weeks`)}>
                                                                <Input
                                                                    type="number"
                                                                    min="0"
                                                                    max="104"
                                                                    bind:value={$form.phases[activePhaseIndex].start_after_previous_phase_weeks}
                                                                    onchange={normalizeActivePhaseStartDelay}
                                                                />
                                                            </Field>
                                                        </div>
                                                    {/if}
                                                </div>
                                            </div>
                                        {/if}
                                    </div>

                                    <div class="border-t border-[#1B2A2A]/10">
                                        <div class="flex items-center justify-between gap-3 px-5 py-4 md:px-7">
                                            <div>
                                                <h4 class="text-sm font-bold">Kruiden & supplementen</h4>
                                                <p class="mt-0.5 text-xs text-[#1B2A2A]/45">Selecteer middelen, frequentie en de concrete weken voor dit paard.</p>
                                            </div>
                                            <span class="text-xs font-semibold text-[#1B2A2A]/45">{activePhase.supplements.length} geselecteerd</span>
                                        </div>
                                        <div class="divide-y divide-[#1B2A2A]/8">
                                            {#each visibleSupplements() as supplement (supplement.id ?? supplement.archived_selection_id)}
                                                {@const selectionIndex = selectedSupplementIndex(supplement)}
                                                {@const selected = selectionIndex >= 0}
                                                {@const selection = selected ? activePhase.supplements[selectionIndex] : null}
                                                {@const displaySupplement = selection ?? supplement}
                                                <div class={`px-5 py-4 transition-colors md:px-7 ${selected ? 'bg-white' : 'bg-[#FBF8F3]/65'}`}>
                                                    <div class="flex items-start gap-3.5">
                                                        <button
                                                            type="button"
                                                            role="switch"
                                                            aria-checked={selected}
                                                            aria-label={`${selected ? 'Verwijder' : 'Voeg toe'} ${displaySupplement.name}`}
                                                            onclick={() => toggleSupplement(supplement)}
                                                            class={`relative mt-0.5 h-[23px] w-10 shrink-0 rounded-full transition-colors ${selected ? 'bg-[#18BAB0]' : 'bg-[#1B2A2A]/20'}`}
                                                        ><span class={`absolute left-[2.5px] top-[2.5px] size-[18px] rounded-full bg-white shadow-sm transition-transform ${selected ? 'translate-x-[17px]' : 'translate-x-0'}`}></span></button>
                                                        <div class={`min-w-0 flex-1 ${selected ? '' : 'opacity-55'}`}>
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <span class="text-sm font-bold">{displaySupplement.name}</span>
                                                                <span class="rounded-full bg-[#EAFBF9] px-2 py-0.5 text-[9px] font-bold uppercase text-[#0E6F69]">{supplementTypeLabel(displaySupplement.supplement_type)}</span>
                                                                {#if displaySupplement.add_by_default}<span class="rounded-full bg-[#FFF8E8] px-2 py-0.5 text-[9px] font-bold uppercase text-[#A06A1B]">Standaard</span>{/if}
                                                                {#if supplement.archived}<span class="rounded-full bg-[#F4EFE7] px-2 py-0.5 text-[9px] font-bold uppercase text-[#6B6258]">Gearchiveerd</span>{/if}
                                                            </div>
                                                            {#if displaySupplement.description}<p class="mt-1 text-xs leading-5 text-[#1B2A2A]/55">{displaySupplement.description}</p>{/if}
                                                            <p class="mt-1 text-[10px] font-semibold text-[#1B2A2A]/40">Min. {displaySupplement.min_aantal_per_week ?? '—'}×/week · max. {displaySupplement.max_aantal_in_fase ?? 'onbeperkt'} in fase · rust {displaySupplement.rust_periode_in_weken ?? '—'} weken</p>
                                                        </div>
                                                    </div>
                                                    {#if selected}
                                                        <div class="ml-[54px] mt-4 grid gap-3 md:grid-cols-2">
                                                            <div>
                                                                <Field label="Dosering voor dit paard" error={errorFor(`phases.${activePhaseIndex}.supplements.${selectionIndex}.dosage`)}>
                                                                    <Input bind:value={$form.phases[activePhaseIndex].supplements[selectionIndex].dosage} placeholder="Bijv. 20 g per dag" />
                                                                </Field>
                                                                {#if displaySupplement.dosis_type === 'per_kg' || displaySupplement.dosis_type === 'per_600_kg'}
                                                                    <p class="mt-1 text-[10px] text-[#1B2A2A]/45">
                                                                        {#if selectedHorse?.weight_kg}
                                                                            {#if displaySupplement.dosis_type === 'per_kg'}
                                                                                Automatisch: {displaySupplement.dosis} {displaySupplement.unit}/kg × {selectedHorse.weight_kg} kg.
                                                                            {:else}
                                                                                Automatisch: {displaySupplement.dosis} {displaySupplement.unit} per 600 kg × ({selectedHorse.weight_kg} / 600).
                                                                            {/if}
                                                                        {:else}
                                                                            Automatische berekening niet mogelijk: het paard heeft geen gewicht.
                                                                        {/if}
                                                                    </p>
                                                                {/if}
                                                            </div>
                                                            <Field label="Aantal per week" error={errorFor(`phases.${activePhaseIndex}.supplements.${selectionIndex}.aantal_per_week`)}><Input type="number" min="0" bind:value={$form.phases[activePhaseIndex].supplements[selectionIndex].aantal_per_week} /></Field>
                                                            <div class="md:col-span-2">
                                                                <Field label="Instructies" error={errorFor(`phases.${activePhaseIndex}.supplements.${selectionIndex}.instructions`)}>
                                                                    <Textarea bind:value={$form.phases[activePhaseIndex].supplements[selectionIndex].instructions} rows="3" placeholder="Optionele instructie voor de klant" />
                                                                </Field>
                                                            </div>
                                                            <div class="md:col-span-2">
                                                                <div class="mb-2 text-xs font-semibold">Toedieningsweken binnen deze fase</div>
                                                                <div class="flex flex-wrap gap-1.5">
                                                                    {#each Array.from({ length: Number(activePhase.week_count || 0) }, (_, index) => index + 1) as week}
                                                                        <button
                                                                            type="button"
                                                                            aria-pressed={selection.week_numbers.includes(week)}
                                                                            onclick={() => toggleSupplementWeek(selectionIndex, week)}
                                                                            class={`min-w-9 rounded-lg border px-2 py-1.5 text-xs font-bold transition ${selection.week_numbers.includes(week) ? 'border-[#18BAB0] bg-[#EAFBF9] text-[#0E6F69]' : 'border-[#1B2A2A]/10 bg-white text-[#1B2A2A]/40 hover:border-[#18BAB0]/50'}`}
                                                                        >{week}</button>
                                                                    {:else}<span class="text-xs text-[#A06A1B]">Voeg eerst weken toe aan deze fase.</span>{/each}
                                                                </div>
                                                                {#if errorFor(`phases.${activePhaseIndex}.supplements.${selectionIndex}.week_numbers`)}<p class="mt-1 text-xs text-destructive">{errorFor(`phases.${activePhaseIndex}.supplements.${selectionIndex}.week_numbers`)}</p>{/if}
                                                                {#if supplementWarning(displaySupplement, selection)}<p class="mt-2 flex items-center gap-1 text-xs font-semibold text-[#A06A1B]"><CircleAlert class="size-3.5" /> {supplementWarning(displaySupplement, selection)}</p>{/if}
                                                            </div>
                                                        </div>
                                                    {/if}
                                                </div>
                                            {:else}
                                                <div class="px-5 py-9 text-center text-sm text-[#1B2A2A]/45 md:px-7">Geen supplementen beschikbaar. Configureer ze in Protocol Templates.</div>
                                            {/each}
                                        </div>
                                    </div>
                                </div>
                            {/if}
                            <div class="mt-6 flex justify-end">
                                <Button type="button" class="rounded-full bg-[#18BAB0] hover:bg-[#108A82]" onclick={() => (activeSection = 'voeding')}>
                                    Naar voeding <ChevronRight class="size-4" />
                                </Button>
                            </div>
                        </section>
                    {:else if activeAdviceCategory}
                        <section class="overflow-hidden rounded-[22px] border border-[#1B2A2A]/10 bg-white">
                            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-[#1B2A2A]/10 px-5 py-6 md:px-7">
                                <div class="max-w-2xl">
                                    <div class="text-[10px] font-bold uppercase tracking-[0.14em] text-[#108A82]">Stap {activeAdviceCategory.step} · {activeAdviceCategory.entity}</div>
                                    <h2 class="mt-1 text-xl font-bold">{activeAdviceCategory.label}</h2>
                                    <p class="mt-1 text-sm text-[#1B2A2A]/50">{activeAdviceCategory.description}</p>
                                </div>
                                <div class="rounded-full bg-[#EAFBF9] px-3 py-1.5 text-xs font-bold text-[#0E6F69]">
                                    {$form[activeAdviceCategory.formKey].length} geselecteerd
                                </div>
                            </div>

                            {#if activeSection === 'voeding'}
                                <div class="space-y-5 border-b p-6">
                                    <h3 class="font-bold">Ruwvoer voor dit paard</h3>
                                    <div class="grid gap-4 md:grid-cols-3">
                                        <Field label="Streefgewicht (kg)" error={$form.errors['customer_settings.target_weight_kg']}><Input type="number" min="1" max="2000" bind:value={$form.customer_settings.target_weight_kg} /></Field>
                                        <Field label="Suiker"><Input bind:value={$form.customer_settings.sugar} /></Field>
                                        <Field label="Eiwit"><Input bind:value={$form.customer_settings.protein} /></Field>
                                    </div>
                                    <p class="text-xs text-muted-foreground">De backend berekent 2–3 kg ruwvoer per 100 kg streefgewicht. Zonder streefgewicht verschijnt geen hoeveelheid.</p>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <Field label="Hooianalyse in de bibliotheek"><Select bind:value={$form.customer_settings.hay_library_item_id} placeholder="Automatisch zoeken" options={libraryItems.map((item) => ({ value: item.id, label: item.title }))} /></Field>
                                        <Field label="Wateranalyse in de bibliotheek"><Select bind:value={$form.customer_settings.water_library_item_id} placeholder="Automatisch zoeken" options={libraryItems.map((item) => ({ value: item.id, label: item.title }))} /></Field>
                                    </div>
                                    <h3 class="font-bold">Bijvoeding uit de intake</h3>
                                    {#each intakeFeeds[$form.horse_id] ?? [] as feed (feed.id)}
                                        <div class="space-y-2 rounded-xl border p-4">
                                            <div class="font-semibold">{feed.name} · {feed.dosage}</div>
                                            <select class="rounded border p-2" value={feedSetting(feed).status} onchange={(e) => updateFeed(feed, 'status', e.currentTarget.value)} aria-label={`Beoordeling ${feed.name}`}><option value="continue">Doorgaan</option><option value="stop">Stoppen</option></select>
                                            <Textarea value={feedSetting(feed).note ?? ''} oninput={(e) => updateFeed(feed, 'note', e.currentTarget.value)} placeholder="Persoonlijke toelichting" />
                                        </div>
                                    {:else}<p class="text-sm text-muted-foreground">Geen bijvoeding ingevuld in een verzonden intake.</p>{/each}
                                </div>
                            {/if}
                            <div class="divide-y divide-[#1B2A2A]/10">
                                {#each activeAdviceCategory.items as advice (advice.id)}
                                    {@const selected = isProtocolAdviceSelected(activeAdviceCategory, advice.id)}
                                    <label class={`flex cursor-pointer items-start gap-4 px-5 py-5 transition-colors md:px-7 ${selected ? 'bg-[#EAFBF9]/55' : 'hover:bg-[#FBF8F3]'}`}>
                                        <input
                                            type="checkbox"
                                            checked={selected}
                                            onchange={() => toggleProtocolAdvice(activeAdviceCategory, advice.id)}
                                            aria-label={`${advice.title} selecteren`}
                                            class="mt-1 size-4 shrink-0 accent-[#18BAB0]"
                                        />
                                        <span class={`min-w-0 flex-1 ${selected ? '' : 'opacity-65'}`}>
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="font-bold">{advice.title}</span>
                                                {#if advice.snapshot}
                                                    <span class="rounded-full bg-white px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-[#0E6F69]">Protocolkopie</span>
                                                {/if}
                                            </span>
                                            <span class="mt-1 block whitespace-pre-line text-sm leading-6 text-[#1B2A2A]/55">{advice.description}</span>
                                        </span>
                                        <span class={`mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full transition ${selected ? 'bg-[#18BAB0] text-white' : 'bg-[#1B2A2A]/5 text-transparent'}`}>
                                            <Check class="size-3.5" />
                                        </span>
                                    </label>
                                    {#if selected && activeSection === 'management'}
                                        <div class="grid gap-3 bg-[#FBF8F3] px-7 py-4 md:grid-cols-2">
                                            <Field label="Categorie"><select class="w-full rounded border p-2" value={managementSetting(advice).category} onchange={(e) => updateManagement(advice, 'category', e.currentTarget.value)}><option value="environment">Leefomgeving & weide</option><option value="care">Lichamelijke zorg</option><option value="monitoring">Onderzoek</option></select></Field>
                                            <Field label="Actie"><select class="w-full rounded border p-2" value={managementSetting(advice).action} onchange={(e) => updateManagement(advice, 'action', e.currentTarget.value)}><option value="do">Aanbevolen</option><option value="avoid">Vermijden / stoppen</option></select></Field>
                                            <Field label="Instructie"><Textarea value={managementSetting(advice).instruction} oninput={(e) => updateManagement(advice, 'instruction', e.currentTarget.value)} /></Field>
                                            <Field label="Persoonlijke toelichting"><Textarea value={managementSetting(advice).note} oninput={(e) => updateManagement(advice, 'note', e.currentTarget.value)} /></Field>
                                            <Field label="Frequentie"><Input value={managementSetting(advice).frequency} oninput={(e) => updateManagement(advice, 'frequency', e.currentTarget.value)} /></Field>
                                            <Field label="Link (https://)"><Input value={managementSetting(advice).url} oninput={(e) => updateManagement(advice, 'url', e.currentTarget.value)} /></Field>
                                            <Field label="Tekst bij link"><Input value={managementSetting(advice).cta_label} oninput={(e) => updateManagement(advice, 'cta_label', e.currentTarget.value)} /></Field>
                                        </div>
                                    {/if}
                                {:else}
                                    <div class="px-6 py-12 text-center">
                                        <p class="font-bold">Geen {activeAdviceCategory.label.toLowerCase()}adviezen beschikbaar</p>
                                        <p class="mt-1 text-sm text-[#1B2A2A]/45">Voeg adviezen toe via Protocol Settings.</p>
                                        <Button type="button" href="/admin/protocol-settings/advice" variant="outline" class="mt-4 rounded-full">Naar Protocol Settings</Button>
                                    </div>
                                {/each}
                            </div>

                            <div class="flex justify-end border-t border-[#1B2A2A]/10 bg-[#FBF8F3] px-5 py-4 md:px-7">
                                <Button type="button" class="rounded-full bg-[#18BAB0] hover:bg-[#108A82]" onclick={() => (activeSection = activeAdviceCategory.next)}>
                                    Naar {activeAdviceCategory.nextLabel} <ChevronRight class="size-4" />
                                </Button>
                            </div>
                        </section>
                    {:else if activeSection === 'content'}
                        <section class="rounded-[22px] border border-[#1B2A2A]/10 bg-white p-5 md:p-7">
                            <div class="mb-7 max-w-xl">
                                <div class="text-[10px] font-bold uppercase tracking-[0.14em] text-[#108A82]">Stap 6</div>
                                <h2 class="mt-1 text-xl font-bold">Analyse</h2>
                                <p class="mt-1 text-sm text-[#1B2A2A]/50">Deze inhoud hoort bij het hele paardprotocol en staat los van de geselecteerde fase.</p>
                            </div>
                            {#if weeklyUpdates.length}
                                <div class="mb-6 space-y-3"><h3 class="font-bold">Weekupdates van de eigenaar</h3>{#each weeklyUpdates as update (update.id)}<div class="rounded-xl bg-[#EAFBF9] p-4"><strong>Week {update.week_number}</strong><p class="mt-2 whitespace-pre-wrap text-sm">{update.note}</p></div>{/each}</div>
                            {/if}
                            <div class="rounded-2xl bg-[#EAFBF9] p-5">
                                <Field label="Persoonlijke analyse" error={$form.errors['analysis.summary']} hint="Maximaal 4 korte zinnen (600 tekens). Benoem opvallende intakesignalen, mogelijke samenhang en de gekozen focus. Formuleer voorzichtig; vermijd onbewezen oorzaak-gevolgclaims.">
                                    <Textarea aria-label="Persoonlijke analyse" bind:value={$form.analysis.summary} rows="4" maxlength="600" />
                                </Field>
                                <p class="mt-2 text-right text-xs text-[#1B2A2A]/50">{$form.analysis.summary.length}/600</p>
                            </div>
                            <div class="mt-7">
                                <div class="mb-4 flex items-center justify-between gap-3">
                                    <div><h3 class="text-base font-bold">Focus van het protocol</h3><p class="mt-0.5 text-xs text-[#1B2A2A]/60">3–4 inhoudelijke focuspunten, elk met één korte doelzin. Geen algemene categorieën zoals Voeding, Management of Training.</p></div>
                                    <Button type="button" variant="outline" class="shrink-0 rounded-full" onclick={addFocusPoint} disabled={$form.analysis.focus_points.length >= 4}><Plus class="size-4" /> Focuspunt</Button>
                                </div>
                                {#if $form.errors['analysis.focus_points']}<p class="mb-3 text-sm text-destructive">{$form.errors['analysis.focus_points']}</p>{/if}
                                <div class="space-y-4">
                                    {#each $form.analysis.focus_points as point, index (index)}
                                        <div class="rounded-xl border border-[#1B2A2A]/10 p-4">
                                            <div class="flex items-start gap-3">
                                                <div class="flex-1"><Field label="Inhoudelijk focuspunt" error={errorFor(`analysis.focus_points.${index}.title`)}><Input aria-label={`Focuspunt ${index + 1}`} bind:value={$form.analysis.focus_points[index].title} maxlength="48" /></Field></div>
                                                <Button type="button" variant="ghost" size="icon" class="mt-6" onclick={() => ($form.analysis.focus_points = $form.analysis.focus_points.filter((_, i) => i !== index))} aria-label={`Focuspunt ${index + 1} verwijderen`}><Trash2 class="size-4 text-destructive" /></Button>
                                            </div>
                                            <div class="mt-3"><Field label="Doel in één korte zin" error={errorFor(`analysis.focus_points.${index}.body`)} hint={`${point.body.length}/160 tekens`}><Textarea aria-label={`Doel ${index + 1}`} bind:value={$form.analysis.focus_points[index].body} rows="2" maxlength="160" /></Field></div>
                                        </div>
                                    {/each}
                                </div>
                            </div>
                            <div class="mt-8">
                                <div class="mb-4 flex items-center justify-between gap-3">
                                    <div><h3 class="text-base font-bold">Waar letten we op?</h3><p class="mt-0.5 text-xs text-[#1B2A2A]/60">1–4 concrete observatiepunten voor de evaluatie. Beschrijf per punt in één korte zin waaraan je merkt of het de goede kant op gaat.</p></div>
                                    <Button type="button" variant="outline" class="shrink-0 rounded-full" onclick={addObservation} disabled={$form.analysis.observations.length >= 4}><Plus class="size-4" /> Observatie</Button>
                                </div>
                                {#if $form.errors['analysis.observations']}<p class="mb-3 text-sm text-destructive">{$form.errors['analysis.observations']}</p>{/if}
                                <div class="space-y-3">
                                    {#each $form.analysis.observations as observation, index (index)}
                                        <div class="flex items-start gap-3">
                                            <div class="flex-1"><Field label={`Observatiepunt ${index + 1}`} error={errorFor(`analysis.observations.${index}`)} hint={`${observation.length}/160 tekens`}><Textarea aria-label={`Observatiepunt ${index + 1}`} bind:value={$form.analysis.observations[index]} rows="2" maxlength="160" /></Field></div>
                                            <Button type="button" variant="ghost" size="icon" class="mt-6" onclick={() => ($form.analysis.observations = $form.analysis.observations.filter((_, i) => i !== index))} aria-label={`Observatiepunt ${index + 1} verwijderen`}><Trash2 class="size-4 text-destructive" /></Button>
                                        </div>
                                    {/each}
                                </div>
                            </div>
                            {#if $form.analysis.cause || $form.advice.length}
                                <details class="mt-8 border-t border-[#1B2A2A]/10 pt-5">
                                    <summary class="cursor-pointer text-sm font-semibold">Eerdere analyse en adviezen (naslag)</summary>
                                    <p class="mt-2 text-xs text-[#1B2A2A]/60">Deze inhoud blijft bewaard. Vul hierboven de compacte klantweergave in; de app gebruikt deze eerdere tekst niet automatisch.</p>
                                    <p class="mt-3 whitespace-pre-wrap text-sm">{$form.analysis.cause}</p>
                                    {#each $form.advice as advice}<div class="mt-3 text-sm"><strong>{advice.title}</strong><p class="whitespace-pre-wrap">{advice.body}</p></div>{/each}
                                </details>
                            {/if}
                            <div class="mt-7 border-t border-[#1B2A2A]/10 pt-5"><Field label="Weekupdate vanaf dag van de protocolweek (1–7)"><Input type="number" min="1" max="7" bind:value={$form.customer_settings.weekly_update_day} /></Field></div>
                        </section>
                    {:else if activeSection === 'preview'}
                        <section class="overflow-hidden rounded-[22px] border border-[#1B2A2A]/10 bg-white">
                            <div class="bg-[#0D5C5B] px-6 py-7 text-white md:px-8">
                                <div class="text-[10px] font-bold uppercase tracking-[0.14em] text-[#99E8DF]">Klantweergave</div>
                                <h2 class="mt-2 text-2xl font-bold">{$form.title || 'Naamloos protocol'}</h2>
                                <p class="mt-1 text-sm text-white/65">{selectedHorse?.name ?? 'Geen paard geselecteerd'} · {displayedProtocolTemplateName}</p>
                                <div class="mt-5 flex flex-wrap gap-2 text-xs font-semibold">
                                    <span class="rounded-full bg-white/10 px-3 py-1.5">{totalWeeks} weken</span>
                                    <span class="rounded-full bg-white/10 px-3 py-1.5">{$form.phases.length} fases</span>
                                    <span class="rounded-full bg-white/10 px-3 py-1.5">{allActiveSupplements.length} supplementen</span>
                                    <span class="rounded-full bg-white/10 px-3 py-1.5">{selectedProtocolAdviceCount} adviezen</span>
                                </div>
                            </div>
                            <div class="space-y-8 px-6 py-7 md:px-8">
                                {#if $form.analysis.summary}
                                    <section class="rounded-2xl bg-[#EAFBF9] p-4"><h3 class="text-xs font-bold uppercase tracking-wider text-[#0E6F69]">Persoonlijke analyse</h3><p class="mt-2 text-sm leading-5">{$form.analysis.summary}</p></section>
                                    <section><h3 class="text-xs font-bold uppercase tracking-wider text-[#1B2A2A]/70">Focus van het protocol</h3><div class="mt-3 space-y-3">{#each $form.analysis.focus_points as point}<div class="rounded-xl border border-[#1B2A2A]/10 bg-white p-4"><strong>{point.title}</strong><p class="mt-1 text-sm">{point.body}</p></div>{/each}</div></section>
                                    <section><h3 class="text-xs font-bold uppercase tracking-wider text-[#1B2A2A]/70">Waar letten we op?</h3><ul class="mt-3 list-disc space-y-2 pl-4 text-sm">{#each $form.analysis.observations as observation}<li>{observation}</li>{/each}</ul></section>
                                {/if}
                                <section>
                                    <div class="text-[10px] font-bold uppercase tracking-[0.12em] text-[#108A82]">Planning</div>
                                    <div class="mt-3 space-y-5">
                                        {#each $form.phases as phase, index (phase.client_key)}
                                            {@const range = phaseRange(index)}
                                            <div class="grid gap-3 border-l-2 border-[#18BAB0]/35 pl-4 md:grid-cols-[170px_minmax(0,1fr)]">
                                                <div><div class="font-bold">{phase.title}</div><div class="mt-1 text-xs text-[#1B2A2A]/45">{range.start ? `Week ${range.start}–${range.end}` : 'Geen weken'}</div></div>
                                                <div>
                                                    {#if phase.supplements.length}<div class="mt-3 flex flex-wrap gap-1.5">{#each phase.supplements as supplement (supplement.id ?? supplement.supplement_id)}<span class="rounded-full bg-[#EAFBF9] px-2.5 py-1 text-[11px] font-semibold text-[#0E6F69]">{supplement.name}</span>{/each}</div>{/if}
                                                </div>
                                            </div>
                                        {/each}
                                    </div>
                                </section>
                                {#if selectedProtocolAdviceCount}
                                    <section>
                                        <div class="text-[10px] font-bold uppercase tracking-[0.12em] text-[#108A82]">Geselecteerde adviezen</div>
                                        <div class="mt-3 space-y-5">
                                            {#each adviceCategories as category (category.id)}
                                                {@const selectedItems = selectedProtocolAdvice(category)}
                                                {#if selectedItems.length}
                                                    <div>
                                                        <div class="text-xs font-bold text-[#1B2A2A]/45">{category.label}</div>
                                                        <div class="mt-2 divide-y divide-[#1B2A2A]/10 rounded-xl bg-[#FBF8F3] px-4">
                                                            {#each selectedItems as advice (advice.id)}
                                                                <div class="py-3">
                                                                    <div class="font-bold">{advice.title}</div>
                                                                    <p class="mt-1 whitespace-pre-line text-sm leading-5 text-[#1B2A2A]/60">{advice.description}</p>
                                                                </div>
                                                            {/each}
                                                        </div>
                                                    </div>
                                                {/if}
                                            {/each}
                                        </div>
                                    </section>
                                {/if}

                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#1B2A2A]/10 bg-[#FBF8F3] px-6 py-4 md:px-8">
                                <p class="text-xs text-[#1B2A2A]/50">{$form.published ? 'Dit protocol is zichtbaar voor de klant.' : 'Dit protocol is nog een concept.'}</p>
                                <Button type="button" class="rounded-full bg-[#18BAB0] hover:bg-[#108A82]" onclick={() => save(true)} disabled={$form.processing}><Send class="size-4" /> {$form.published ? 'Wijzigingen publiceren' : 'Protocol publiceren'}</Button>
                            </div>
                        </section>
                    {/if}
                </main>
            </div>
        </form>

        {#if overviewOpen}
            <div class="fixed inset-0 z-50 flex justify-end bg-[#0B4A49]/45">
                <button type="button" class="absolute inset-0 cursor-default" aria-label="Preview sluiten" onclick={() => (overviewOpen = false)}></button>
                <div class="relative h-full w-full max-w-xl overflow-y-auto bg-[#FBF8F3] p-6 shadow-2xl" role="dialog" aria-modal="true" aria-label="Volledige klantpreview">
                    <div class="flex items-start justify-between gap-3"><div><div class="text-xs font-bold uppercase tracking-[0.12em] text-[#108A82]">Volledig protocol</div><h2 class="mt-1 text-xl font-bold">{$form.title}</h2><p class="mt-1 text-sm text-[#1B2A2A]/55">{selectedHorse?.name} · {totalWeeks} weken</p></div><Button type="button" variant="ghost" size="icon" onclick={() => (overviewOpen = false)} aria-label="Preview sluiten"><X class="size-5" /></Button></div>
                    <div class="mt-7 space-y-7">
                        {#each $form.phases as phase, index (phase.client_key)}
                            {@const range = phaseRange(index)}
                            <section><div class="flex items-baseline justify-between gap-3"><h3 class="font-bold">{phase.title}</h3><span class="text-xs text-[#1B2A2A]/45">{range.start ? `Week ${range.start}–${range.end}` : 'Geen weken'}</span></div>{#if phase.supplements.length}<div class="mt-3 flex flex-wrap gap-1.5">{#each phase.supplements as supplement (supplement.id ?? supplement.supplement_id)}<span class="rounded-full bg-[#EAFBF9] px-2.5 py-1 text-xs font-semibold text-[#0E6F69]">{supplement.name}</span>{/each}</div>{/if}</section>
                        {/each}
                    </div>
                </div>
            </div>
        {/if}

        {#if typeChangeDialogOpen}
            <div class="fixed inset-0 z-50 grid place-items-center bg-[#0B4A49]/45 p-4">
                <button type="button" class="absolute inset-0 cursor-default" aria-label="Dialoog sluiten" onclick={cancelProtocolTemplateChange}></button>
                <div class="relative w-full max-w-md rounded-[22px] bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-label="Protocol template wijzigen">
                    <div class="flex items-start gap-3"><CircleAlert class="mt-0.5 size-5 shrink-0 text-[#A06A1B]" /><div><h2 class="text-lg font-bold">Protocol template wijzigen?</h2><p class="mt-1 text-sm leading-6 text-[#1B2A2A]/55">De huidige conceptplanning en taken worden vervangen door de verplichte fases en standaardsupplementen van de nieuwe template.</p></div></div>
                    <div class="mt-6 flex justify-end gap-2"><Button type="button" variant="outline" onclick={cancelProtocolTemplateChange}>Behouden</Button><Button type="button" class="bg-[#18BAB0] hover:bg-[#108A82]" onclick={applyProtocolTemplateChange}>Planning vervangen</Button></div>
                </div>
            </div>
        {/if}
    </div>
</AdminLayout>
