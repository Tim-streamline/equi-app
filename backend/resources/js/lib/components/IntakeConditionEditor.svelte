<script>
    import ConditionEditor from './IntakeConditionEditor.svelte';
    let { value = null, questions = [], onchange, depth = 0 } = $props();
    const entries = $derived(value && typeof value === 'object' ? Object.entries(value) : []);
    const group = $derived(entries.length === 1 && ['$all', '$any', '$not'].includes(entries[0][0]) ? entries[0][0] : entries.length > 1 ? '$all' : null);
    const children = $derived(group === '$not' ? [value.$not] : group && entries.length === 1 ? value[group] : group ? entries.map(([key, answer]) => ({ [key]: answer })) : []);
    const key = $derived(!group && entries.length ? entries[0][0] : '');
    const answer = $derived(!group && entries.length ? entries[0][1] : '');
    const question = $derived(questions.find(q => q.key === key));
    // A {gte} object is a minimum; anything else is a list of answers. The form always shows what is saved.
    const minimumMode = $derived(isMinimum(answer));
    function isMinimum(answer) { return !!answer && typeof answer === 'object' && !Array.isArray(answer); }
    /** A new condition starts complete: a number question at minimum 1, otherwise its first answer. */
    function initialRule(questionKey) {
        const q = questions.find(q => q.key === questionKey);
        return { [questionKey]: q?.type === 'number' ? { gte: 1 } : q?.options?.[0] ?? '' };
    }
    /** An empty minimum stays empty, so saving is refused instead of silently becoming 0. */
    function minimumRule(text) { return { [key]: { gte: text === '' ? '' : Number(text) } }; }
    function modeRule(mode) { return mode === 'gte' ? { [key]: { gte: 1 } } : { [key]: question?.options?.[0] ?? '' }; }
    function replaceChild(index, rule) {
        const next = children.map((child, i) => i === index ? rule : child).filter(Boolean);
        onchange(next.length ? group === '$not' ? { $not: next[0] } : { [group]: next } : null);
    }
    function chooseField(next) { onchange(initialRule(next)); }
</script>

<div class="space-y-3 rounded-xl border border-[#1B2A2A]/15 bg-white p-3">
    {#if !entries.length}
        <p class="text-sm text-[#1B2A2A]/60">Altijd zichtbaar.</p>
        <button type="button" class="text-sm font-semibold text-[#0E6F69]" onclick={() => chooseField(questions[0]?.key ?? '')}>Voorwaarde toevoegen</button>
    {:else if group}
        <details open={depth === 0}>
        <summary class="cursor-pointer text-sm font-semibold text-[#0E6F69]">{group === '$all' ? 'Alle voorwaarden gelden' : group === '$any' ? 'Minstens één voorwaarde geldt' : 'Deze voorwaarde geldt niet'} · {children.length}</summary>
        <div class="mt-3 space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <select aria-label="Voorwaardegroep" class="rounded-lg border p-2 text-sm" value={group} onchange={e => onchange(e.currentTarget.value === '$not' ? { $not: children[0] } : { [e.currentTarget.value]: children })}>
                <option value="$all">Alle voorwaarden gelden</option><option value="$any">Minstens één voorwaarde geldt</option><option value="$not">Deze voorwaarde geldt niet</option>
            </select>
            <button type="button" class="text-sm text-red-700" onclick={() => onchange(null)}>Groep verwijderen</button>
        </div>
        {#each children as child, i}<ConditionEditor value={child} {questions} depth={depth + 1} onchange={rule => replaceChild(i, rule)} />{/each}
        {#if group !== '$not'}<button type="button" class="text-sm font-semibold text-[#0E6F69]" onclick={() => onchange({ [group]: [...children, initialRule(questions[0]?.key ?? '')] })}>Voorwaarde toevoegen</button>{/if}
        </div></details>
    {:else}
        <select aria-label="Vraag voor voorwaarde" class="w-full rounded-lg border p-2 text-sm" value={key} onchange={e => chooseField(e.currentTarget.value)}>
            {#if !question}<option value={key}>{key}</option>{/if}
            {#each questions as q}<option value={q.key}>{q.label}</option>{/each}
        </select>
        {#if question?.type === 'number' && question?.options?.length}
            <select aria-label="Soort voorwaarde" class="rounded-lg border p-2 text-sm" value={minimumMode ? 'gte' : 'options'} onchange={e => onchange(modeRule(e.currentTarget.value))}>
                <option value="gte">Minimaal een getal</option><option value="options">Eén van deze antwoorden</option>
            </select>
        {/if}
        {#if minimumMode}
            <label class="block text-sm">Minimaal <input aria-label="Minimale waarde" type="number" class="ml-2 w-24 rounded-lg border p-2" value={answer.gte ?? ''} oninput={e => onchange(minimumRule(e.currentTarget.value))} /></label>
            {#if answer.gte === '' || answer.gte === null || answer.gte === undefined}<p class="text-sm text-red-700">Vul een minimum in.</p>{/if}
        {:else if question?.options?.length}
            <fieldset class="max-h-48 space-y-2 overflow-y-auto"><legend class="text-sm mb-2">Eén van deze antwoorden</legend>
                {#each question.options as option}
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-1 accent-[#0E6F69]" checked={(Array.isArray(answer) ? answer : [answer]).includes(option)} onchange={e => {
                        const next = (Array.isArray(answer) ? answer : [answer]).filter(v => v !== option && v !== '');
                        if (e.currentTarget.checked) next.push(option);
                        onchange({ [key]: next.length ? next : '' });
                    }} />{option}</label>
                {/each}
            </fieldset>
        {:else}
            <input aria-label="Antwoord voor voorwaarde" class="w-full rounded-lg border p-2 text-sm" value={Array.isArray(answer) ? answer.join(', ') : answer} oninput={e => onchange({ [key]: e.currentTarget.value })} />
        {/if}
        <div class="flex flex-wrap gap-4 text-sm">
            <button type="button" class="text-red-700" onclick={() => onchange(null)}>Verwijderen</button>
            <button type="button" class="text-[#0E6F69]" onclick={() => onchange({ $all: [value, initialRule(questions[0]?.key ?? '')] })}>En nog een voorwaarde</button>
            <button type="button" class="text-[#0E6F69]" onclick={() => onchange({ $any: [value, initialRule(questions[0]?.key ?? '')] })}>Of een andere voorwaarde</button>
            <button type="button" class="text-[#0E6F69]" onclick={() => onchange({ $not: value })}>Omgekeerde voorwaarde</button>
        </div>
    {/if}
</div>
