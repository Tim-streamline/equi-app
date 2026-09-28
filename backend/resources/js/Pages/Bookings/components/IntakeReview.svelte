<script>
    import { onMount } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import { Camera, ShieldCheck, ListChecks, NotebookPen, X, ArrowUpRight } from '@lucide/svelte';
    import { csrfHeaders } from '$lib/csrf.js';
    import Answer from './Answer.svelte';

    let { booking, review, printMode = false } = $props();
    let filter = $state('all');
    let active = $state('');
    let notes = $state(review.notes);
    let accepted = $state([...review.accepted]);
    let saveStatus = $state('Opgeslagen');
    let photo = $state(null);
    let timer;
    let pending = false;
    let saving = null;
    let root;
    const allRows = $derived(review.sections.flatMap(s => s.rows));
    const summary = $derived(['ras', 'leeftijd', 'geboortedatum', 'geslacht', 'gewicht', 'gewicht-methode', 'stokmaat', 'stokmaat-methode', 'conditie'].map(key => allRows.find(r => r.section === 'paard' && r.key === key)).filter(Boolean));
    const portrait = $derived(allRows.find(r => r.section === 'fysiek' && r.key === 'foto-zijaanzicht-links')?.attachments?.[0]);
    const matching = (row) => filter === 'all' || (row.type !== 'sectionhead' && (filter === 'flags' ? row.flagged : filter === 'protocol' ? row.protocol.length > 0 : row.empty));
    const sections = $derived(review.sections.map(s => ({ ...s, rows: s.rows.filter(matching) })).filter(s => s.rows.length));
    const filters = [['all', 'Alle antwoorden'], ['flags', 'Aandachtspunten'], ['protocol', 'Protocol-triggers'], ['empty', 'Niet ingevuld']];

    function jump(id, reveal = false) {
        if (reveal) filter = 'all';
        requestAnimationFrame(() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    }
    function openDialog(node) { node.showModal(); }
    function changed() {
        pending = true;
        saveStatus = 'Opslaan…';
        clearTimeout(timer);
        timer = setTimeout(() => void flush(), 500);
    }
    function toggle(id, checked) {
        accepted = checked ? [...accepted, id] : accepted.filter(value => value !== id);
        changed();
    }
    export async function flush() {
        clearTimeout(timer);
        if (saving) { await saving; return pending ? flush() : saveStatus === 'Opgeslagen'; }
        if (!pending) return saveStatus === 'Opgeslagen';
        pending = false;
        saving = (async () => {
            try {
                const response = await fetch(`/admin/bookings/${booking.id}/review`, {
                    method: 'PATCH', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
                    body: JSON.stringify({ notes, accepted_triggers: accepted }),
                });
                if (!response.ok) throw new Error('Opslaan mislukt');
                saveStatus = pending ? 'Opslaan…' : 'Opgeslagen';
                return true;
            } catch {
                pending = true;
                saveStatus = 'Niet opgeslagen. Controleer je verbinding en probeer opnieuw.';
                return false;
            } finally { saving = null; }
        })();
        const ok = await saving;
        return ok && pending ? flush() : ok;
    }
    onMount(() => {
        let navigating = false;
        const removeBefore = router.on('before', event => {
            const visit = event.detail.visit;
            if (visit.prefetch || visit.method !== 'get' || (!pending && !saving)) return;
            event.preventDefault();
            if (navigating) return;
            navigating = true;
            void flush().then(ok => {
                navigating = false;
                if (ok) router.visit(visit.url, visit);
            });
        });
        const beforeUnload = e => { if (pending || saving) { e.preventDefault(); e.returnValue = ''; } };
        const scroll = () => {
            const candidates = [...root.querySelectorAll('[data-section]')];
            // The admin shell scrolls its main container, so listen in the capture phase.
            active = (candidates.find(el => el.getBoundingClientRect().bottom > 180) ?? candidates.at(-1))?.dataset.section ?? '';
        };
        window.addEventListener('beforeunload', beforeUnload);
        document.addEventListener('scroll', scroll, true);
        scroll();
        return () => { removeBefore(); clearTimeout(timer); window.removeEventListener('beforeunload', beforeUnload); document.removeEventListener('scroll', scroll, true); };
    });
</script>

<svelte:window onkeydown={event => { if (event.key === 'Escape') photo = null; }} />
<div class:printing={printMode} class="intake-review" bind:this={root}>
    <section class="summary surface">
        <div class="horse-summary">
            {#if portrait}<button class="portrait" onclick={() => photo = portrait}><img src={portrait.url} alt="Foto van het paard" /></button>{:else}<div class="portrait empty-portrait"><Camera size={28} /></div>{/if}
            <dl>{#each summary as row}<div><dt>{row.label}</dt><dd><Answer {row} {printMode} /></dd></div>{/each}</dl>
        </div>
        <div class="help">
            <h3>Hulpvraag</h3><p>{review.answers.klacht?.hulpvraag || 'Niet ingevuld'}</p>
            <h3>Wens</h3><p class="italic">{review.answers.klacht?.wens || 'Niet ingevuld'}</p>
            {#if allRows.find(r => r.section === 'klacht' && r.key === 'thema')}
                <Answer row={allRows.find(r => r.section === 'klacht' && r.key === 'thema')} />
            {/if}
        </div>
    </section>
    {#if !printMode}
        <div class="filters" aria-label="Filter intake-antwoorden">
            {#each filters as [key, label]}<button class:chosen={filter === key} aria-pressed={filter === key} onclick={() => filter = key}>{label} <span>{review.counts[key]}</span></button>{/each}
        </div>
    {/if}
    <div class="review-columns">
        {#if !printMode}<nav class="section-nav" aria-label="Onderdelen"><h3>Onderdelen</h3>
            {#each sections as section}<button class:current={active === section.id} onclick={() => jump(`section-${section.id}`)}><span>{String(section.nr).padStart(2, '0')}</span><span>{section.title}</span>{#if section.flags}<b>{section.flags}</b>{/if}</button>{/each}
        </nav>{/if}
        <div class="answers">
            {#each sections as section}
                <section class="surface section" id={`section-${section.id}`} data-section={section.id}>
                    <header><span class="number">{String(section.nr).padStart(2, '0')}</span><div><h2>{section.title}</h2><p>{section.count} vragen{section.flags ? ` · ${section.flags} aandachtspunten` : ''}</p></div></header>
                    {#each section.rows as row}
                        {#if row.type === 'sectionhead'}<h3 class="subheading">{row.label}</h3>
                        {:else}<div class="answer-row" id={`answer-${row.id}`}>
                            <div class="question"><span>{row.label}</span>
                                {#if row.critical}<small class="critical">Blokkeert protocol</small>{:else if row.flagged}<small class="attention">Aandachtspunt</small>{/if}
                                {#if row.required && row.empty}<small class="missing">Verplicht, niet ingevuld</small>{/if}
                            </div>
                            <div class="answer"><Answer {row} {printMode} openPhoto={file => photo = file} />
                                {#each row.protocol as text}<div class="protocol">Protocol: {text}</div>{/each}
                            </div>
                        </div>{/if}
                    {/each}
                </section>
            {:else}<p class="surface p-6">Geen antwoorden voor dit filter.</p>{/each}
        </div>
        <aside class="review-sidebar">
            <section class="surface"><header><ShieldCheck size={20}/><h2>Veiligheidscheck</h2></header>
                <p class:critical={review.blocked} class="safety-status">{review.blocked ? 'Geblokkeerd' : review.safety.some(r => r.empty) ? 'Nog niet volledig ingevuld' : 'Geen blokkades'}</p>
                {#each review.safety as row}<button class="safety-item" onclick={() => jump(`answer-${row.id}`, true)}><span class:danger={row.critical} class:unknown={row.empty} class="dot"></span><span>{row.label}<small>{row.empty ? 'Niet ingevuld' : Array.isArray(row.value) ? row.value.join(', ') : row.value}</small></span></button>{/each}
                {#if !review.safety.length}<p>Geen veiligheidsregels voor zichtbare vragen geconfigureerd.</p>{/if}
            </section>
            <section class="surface"><header><ListChecks size={20}/><h2>Protocol-bouwstenen</h2></header><p class="muted">{review.triggers.length} automatisch herkend · {accepted.length} overgenomen</p>
                {#each review.triggers as row}<div class="trigger">
                    <label><input type="checkbox" checked={accepted.includes(row.id)} disabled={printMode} onchange={e => toggle(row.id, e.currentTarget.checked)}/><span>{row.protocol.join('\n')}</span></label>
                    <button class="source" onclick={() => jump(`answer-${row.id}`, true)}>{row.section_title} · {row.label} = {Array.isArray(row.value) ? row.value.join(', ') : row.value} <ArrowUpRight size={14}/></button>
                </div>{:else}<p class="muted mt-3">Geen protocol-aanpassingen herkend op basis van de ingestelde vragenlijstregels.</p>{/each}
            </section>
            <section class="surface"><header><NotebookPen size={20}/><h2>Mijn notities</h2></header>
                {#if printMode}<p class="whitespace-pre-wrap">{notes || 'Geen notities'}</p>{:else}
                    <textarea aria-label="Mijn notities" bind:value={notes} oninput={changed} placeholder="Eerste gedachten voor het protocol: vermoedelijke oorzaak, volgorde, vragen voor de eigenaar…" rows="8"></textarea>
                    <p aria-live="polite" class="muted text-sm">{saveStatus}</p>
                    {#if saveStatus.startsWith('Niet opgeslagen')}<button class="source" onclick={() => void flush()}>Opnieuw opslaan</button>{/if}
                {/if}
            </section>
        </aside>
    </div>
</div>
{#if photo}
    <dialog use:openDialog class="lightbox" aria-label={photo.name} oncancel={() => photo = null} onclick={e => { if (e.target === e.currentTarget) photo = null; }}>
        <section><button aria-label="Sluiten" onclick={() => photo = null}><X/></button><img src={photo.url} alt={photo.name}/><a href={`${photo.url}?download=1`}>Download {photo.name}</a></section>
    </dialog>
{/if}
<style>
    .intake-review { color: #1b2a2a; font-size: 15px; line-height: 1.5; }
    .surface { background: white; border: 1px solid #1b2a2a14; border-radius: 20px; }
    .summary { padding: 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 24px; }
    .horse-summary { display: flex; gap: 20px; }
    .portrait { flex-shrink: 0; width: 104px; height: 130px; border-radius: 16px; overflow: hidden; }
    .portrait img { width: 100%; height: 100%; object-fit: cover; }
    .empty-portrait { display: grid; place-items: center; background: #f4f0e9; color: #8c9490; }
    dl { min-width: 0; } dl div { display: grid; grid-template-columns: minmax(90px, 1fr) 1.4fr; gap: 10px; margin-bottom: 4px; } dt { color: #63716b; } dd { font-weight: 600; }
    h3 { font-size: 12px; letter-spacing: .12em; text-transform: uppercase; font-weight: 600; }
    .help h3 { color: #147d75; margin-bottom: 6px; } .help p { white-space: pre-wrap; margin-bottom: 16px; }
    .filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; }
    .filters button { min-height: 44px; padding: 8px 18px; border: 1px solid #dde3df; border-radius: 999px; background: white; font-weight: 600; }
    .filters button.chosen { background: #0c3735; color: white; border-color: #0c3735; } .filters span { background: #8882; padding: 1px 6px; border-radius: 8px; margin-left: 5px; }
    .review-columns { display: grid; grid-template-columns: 160px minmax(0, 1fr) 280px; align-items: start; gap: 20px; }
    .section-nav, .review-sidebar { position: sticky; top: 16px; } .section-nav h3 { color: #6d7e75; margin-bottom: 10px; }
    .section-nav button { display: grid; grid-template-columns: 24px 1fr auto; align-items: center; gap: 8px; text-align: left; width: 100%; padding: 9px 8px; border-radius: 10px; font-size: 13px; }
    .section-nav button.current { background: #e2f5f0; color: #086e65; font-weight: 600; } .section-nav b { background: #fff0d4; color: #825507; padding: 0 6px; border-radius: 9px; }
    .answers { min-width: 0; } .section { padding: 24px; margin-bottom: 20px; scroll-margin-top: 20px; }
    header { display: flex; gap: 10px; align-items: center; margin-bottom: 16px; } h2 { font-weight: 600; font-size: 16px; } header p, .muted { color: #6c7771; font-size: 14px; }
    .number { padding: 7px 10px; background: #f4f0e9; border-radius: 8px; font-weight: 600; }
    .subheading { margin: 24px 0 12px; color: #6c7771; }
    .answer-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 20px; padding: 16px 0; border-top: 1px solid #1b2a2a0d; scroll-margin-top: 30px; break-inside: avoid; }
    .question { display: flex; flex-direction: column; align-items: flex-start; gap: 7px; } .answer { min-width: 0; overflow-wrap: anywhere; }
    small.attention, small.critical, .missing { font-size: 11px; padding: 2px 8px; border-radius: 999px; }
    .attention { background: #fff0d4; color: #825507; } .critical { background: #fee9e8; color: #a12d2d; } .missing { background: #f2f0eb; color: #6c6255; }
    .protocol { background: #ebf8f1; color: #145e4d; border-radius: 10px; padding: 10px; margin-top: 10px; font-size: 14px; }
    .review-sidebar { display: grid; gap: 16px; } .review-sidebar .surface { padding: 20px; } .safety-status { border-radius: 999px; padding: 3px 10px; display: inline-block; font-size: 12px; margin-bottom: 12px; background: #ebf8f1; } .safety-status.critical { background: #fee9e8; }
    .safety-item { display: flex; gap: 10px; text-align: left; width: 100%; margin-top: 12px; font-size: 14px; } .safety-item small { display: block; color: #6c7771; }
    .dot { width: 8px; height: 8px; flex-shrink: 0; margin-top: 6px; border-radius: 100%; background: #29a780; } .dot.danger { background: #c44848; } .dot.unknown { background: #a6aaa7; }
    .trigger { border-top: 1px solid #eee; padding-top: 14px; margin-top: 14px; } .trigger label { display: flex; gap: 10px; white-space: pre-wrap; font-weight: 600; } input { accent-color: #108a82; margin-top: 5px; }
    .source { text-align: left; color: #108a82; font-size: 13px; display: inline; margin-top: 8px; } .source :global(svg) { display: inline; }
    textarea { width: 100%; background: #fbf8f3; border: 1px solid #e7e3dd; border-radius: 12px; padding: 12px; resize: vertical; min-height: 180px; }
    button, a { cursor: pointer; } button:focus-visible, a:focus-visible, textarea:focus-visible { outline: 2px solid #108a82; outline-offset: 3px; }
    .lightbox { margin: 0; width: 100vw; height: 100vh; max-width: none; max-height: none; border: 0; position: fixed; inset: 0; z-index: 100; background: #102523dd; display: grid; place-items: center; padding: 30px; } .lightbox section { position: relative; text-align: center; color: white; } .lightbox img { max-height: 80vh; max-width: 85vw; } .lightbox button { position: absolute; top: -28px; right: -20px; }
    @media (max-width: 1280px) { .section-nav { display: none; } .review-columns { grid-template-columns: minmax(0, 1fr) 280px; } .review-sidebar { grid-column: 2; } }
    @media (max-width: 1000px) { .summary { grid-template-columns: 1fr; } .review-columns { grid-template-columns: 1fr; } .section-nav { position: static; display: none; } .review-sidebar { grid-column: 1; grid-row: 1; position: static; } }
    @media (max-width: 580px) { .summary, .section { padding: 16px; } .horse-summary { flex-direction: column; } .answer-row { grid-template-columns: 1fr; gap: 10px; } }
    .printing .review-columns { display: block; } .printing .review-sidebar { position: static; } .printing .section { break-inside: auto; } .printing .summary { grid-template-columns: 1fr; }
    @media print { .surface { box-shadow: none; } .summary { break-inside: avoid; } .review-sidebar .surface { margin-top: 20px; break-inside: avoid; } }
</style>
