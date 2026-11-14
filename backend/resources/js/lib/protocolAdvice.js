// Per-protocol advice overrides, stored in `customer_settings` under one group per editor section.
const GROUPS = { voeding: 'nutrition_advice', management: 'management', beweging: 'movement' };

const managementCategory = (title) => /mestonderzoek|bloed|urine|onderzoek|monitor/i.test(title) ? 'monitoring'
    : /hoef|gebit|tand|behandel|verzorg/i.test(title) ? 'care' : 'environment';

function defaultSetting(section, advice) {
    if (section === 'voeding') return { id: advice.id, instruction: advice.description };
    const fields = { action: 'do', instruction: advice.description, note: '', frequency: '', url: '', cta_label: '' };
    return section === 'beweging'
        ? { id: advice.id, ...fields }
        : { id: advice.id, category: managementCategory(advice.title), ...fields };
}

// What the editor shows for one advice: the stored override, or the defaults for its section.
// A cleared (null) instruction shows the protocol snapshot, or the library text for a new selection.
export function adviceSetting(customerSettings, section, advice) {
    const row = customerSettings[GROUPS[section]].find((candidate) => candidate.id === advice.id);
    return row ? { ...row, instruction: row.instruction ?? advice.description } : defaultSetting(section, advice);
}

// The section's override rows with one field of one advice changed.
export function updatedAdviceSettings(customerSettings, section, advice, key, value) {
    const rows = customerSettings[GROUPS[section]];
    // Keep the instruction null so editing another field does not pin the displayed text as a custom instruction.
    const index = rows.findIndex((row) => row.id === advice.id);
    const changed = { ...(rows[index] ?? { ...defaultSetting(section, advice), instruction: null }), [key]: value };
    // Validation errors refer to rows by index, so a row keeps its position; only a new row is appended.
    return { [GROUPS[section]]: index < 0 ? [...rows, changed] : rows.map((row, i) => (i === index ? changed : row)) };
}

// The validation errors of one advice's override row, keyed by field; `row` holds an error on the row itself.
export function adviceSettingErrors(errors, customerSettings, section, advice) {
    const index = customerSettings[GROUPS[section]].findIndex((row) => row.id === advice.id);
    if (index < 0) return {};
    const prefix = `customer_settings.${GROUPS[section]}.${index}`;
    return Object.fromEntries(Object.entries(errors).flatMap(([path, message]) => {
        if (path === prefix) return [['row', message]];
        return path.startsWith(`${prefix}.`) ? [[path.slice(prefix.length + 1), message]] : [];
    }));
}

// Advice the editor still has to show although `items` no longer offers it (archived while the editor was open):
// a selection that can only be unticked, and a kept override row whose errors have to stay reachable.
export function unofferedAdvice(errors, customerSettings, section, items, selectedIds, knownItems = []) {
    const errored = customerSettings[GROUPS[section]]
        .filter((row) => Object.keys(adviceSettingErrors(errors, customerSettings, section, row)).length > 0)
        .map((row) => row.id);
    return [...new Set([...selectedIds, ...errored])]
        .filter((id) => !items.some((item) => item.id === id))
        .map((id) => ({ title: 'Niet meer beschikbaar advies', description: '', ...knownItems.find((item) => item.id === id), id, unoffered: true }));
}

// The section and advice id an error path such as `customer_settings.movement.2.url` refers to.
export function adviceErrorTarget(path, customerSettings) {
    const [group, index] = path.split('.').slice(1);
    const section = Object.keys(GROUPS).find((key) => GROUPS[key] === group);
    if (!path.startsWith('customer_settings.') || !section) return null;
    return { section, id: customerSettings[group][Number(index)]?.id ?? null };
}

// What the customer reads for a protocol's advice snapshot: its override instruction, or the snapshot text.
export function snapshotInstruction(customerSettings, section, snapshot) {
    const row = customerSettings?.[GROUPS[section]]?.find((candidate) => candidate.id === snapshot[`${section}_advies_id`]);
    return row?.instruction ?? snapshot.description;
}
