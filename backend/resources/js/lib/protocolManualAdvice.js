/**
 * Manual advice in the protocol editor: advice the therapist writes for this protocol only, outside the
 * measure library. Rows refer to phases by the form's phase client_key; a row limited to phases keeps
 * `applies_to: 'phases'` when its phases are removed, so it never silently starts to apply to the whole
 * protocol. Saving then asks for a new choice.
 */

export const managementCategoryOptions = [
    { value: 'environment', label: 'Leefomgeving & weide' },
    { value: 'care', label: 'Lichamelijke zorg' },
    { value: 'monitoring', label: 'Onderzoek' },
];

export const actionOptions = [
    { value: 'do', label: 'Aanbevolen' },
    { value: 'avoid', label: 'Vermijden / stoppen' },
];

function makeKey() {
    return `manual-${globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2)}`;
}

/** Form rows from the stored protocol; stored phases use their id as client_key. */
export function manualAdviceFromProtocol(rows = []) {
    return rows.map((row) => ({
        id: row.id,
        client_key: row.id,
        category: row.category,
        title: row.title ?? '',
        description: row.description ?? '',
        management_category: row.management_category ?? (row.category === 'management' ? 'environment' : null),
        action: row.action ?? 'do',
        applies_to: row.applies_to ?? 'protocol',
        phase_keys: (row.phases ?? []).map((phase) => phase.id),
    }));
}

export function emptyManualAdvice(category) {
    return {
        id: null,
        client_key: makeKey(),
        category,
        title: '',
        description: '',
        management_category: category === 'management' ? 'environment' : null,
        action: 'do',
        applies_to: 'protocol',
        phase_keys: [],
    };
}

export function manualAdviceForCategory(rows, category) {
    return rows.filter((row) => row.category === category);
}

/** Adds the draft, or replaces the row with the same client_key in place. */
export function saveManualAdvice(rows, draft) {
    const clean = {
        ...draft,
        title: draft.title.trim(),
        description: draft.description.trim(),
        phase_keys: draft.applies_to === 'phases' ? [...new Set(draft.phase_keys)] : [],
    };
    return rows.some((row) => row.client_key === draft.client_key)
        ? rows.map((row) => (row.client_key === draft.client_key ? clean : row))
        : [...rows, clean];
}

export function removeManualAdvice(rows, clientKey) {
    return rows.filter((row) => row.client_key !== clientKey);
}

/** Drops links to phases that are no longer in the plan; the rows stay limited to phases. */
export function keepPlannedPhases(rows, phases) {
    const planned = new Set(phases.map((phase) => phase.client_key));
    return rows.map((row) => {
        const phaseKeys = row.phase_keys.filter((key) => planned.has(key));
        return phaseKeys.length === row.phase_keys.length ? row : { ...row, phase_keys: phaseKeys };
    });
}

export function needsPhaseChoice(row) {
    return row.applies_to === 'phases' && row.phase_keys.length === 0;
}

/** Problems that keep the draft from being added; the server checks the same on save. */
export function manualAdviceDraftErrors(draft) {
    const errors = {};
    if (!draft.title.trim()) errors.title = 'Geef het advies een titel.';
    if (!draft.description.trim()) errors.description = 'Geef het advies een omschrijving.';
    if (draft.category === 'management' && !draft.management_category) errors.management_category = 'Kies een onderdeel.';
    if (needsPhaseChoice(draft)) errors.phase_keys = 'Kies minimaal één fase of laat het advies voor het hele protocol gelden.';
    return errors;
}

export function phaseSummary(row, phases) {
    if (row.applies_to !== 'phases') return 'Hele protocol';
    const titles = phases.filter((phase) => row.phase_keys.includes(phase.client_key)).map((phase) => phase.title);
    return titles.length ? titles.join(' · ') : 'Geen fase gekozen';
}

/** Error messages of one manual advice row, keyed by field, from the form's flat error bag. */
export function manualAdviceErrors(errors, rows, clientKey) {
    const index = rows.findIndex((row) => row.client_key === clientKey);
    if (index < 0) return {};
    const prefix = `manual_advice.${index}.`;
    return Object.fromEntries(Object.entries(errors)
        .filter(([path]) => path.startsWith(prefix))
        .map(([path, message]) => [path.slice(prefix.length).split('.')[0], message]));
}
