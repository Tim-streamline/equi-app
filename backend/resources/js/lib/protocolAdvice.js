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
    const current = rows.find((row) => row.id === advice.id) ?? { ...defaultSetting(section, advice), instruction: null };
    return { [GROUPS[section]]: [...rows.filter((row) => row.id !== advice.id), { ...current, [key]: value }] };
}
