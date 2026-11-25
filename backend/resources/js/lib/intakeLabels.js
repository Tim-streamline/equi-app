export function labelDefinitions(field, key = '') {
    // The top-level options editor trims on save. Repeater JSON keeps exact answer identities.
    const options = Array.isArray(field.options) ? field.options.filter(x => typeof x === 'string' && x.trim()) : [];
    const choices = [...new Set(key ? options : options.map(x => x.trim()))];
    const nodes = field.type === 'sectionhead' ? [] : [{ key, title: field.label || 'Nieuwe vraag', options: choices }];
    for (const sub of Array.isArray(field.sub) ? field.sub : []) {
        if (sub && typeof sub.id === 'string' && sub.id !== '') nodes.push(...labelDefinitions(sub, key ? `${key}/${sub.id}` : sub.id));
    }
    return nodes;
}

// Keep identity tied to the answer text, never its position in the options list.
export function editIntakeLabel(config, key, answer, value) {
    const next = structuredClone(config || []);
    let node = next.find(item => item.key === key);
    if (!node) { node = { key, label: '', answers: [] }; next.push(node); }
    if (answer === null) node.label = value;
    else {
        const row = node.answers.find(item => item.answer === answer);
        if (row) row.label = value;
        else node.answers.push({ answer, label: value });
    }
    return next;
}

export function submittedIntakeLabels(config) {
    // Missing defaults are allocated by the backend from the current admin data.
    return (config || []).map(node => ({ ...node, label: node.label || null, answers: node.answers.filter(row => row.label) }));
}
