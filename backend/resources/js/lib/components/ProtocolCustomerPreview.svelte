<script>
    import { X, ArrowLeft, Leaf, Check, ChevronRight } from '@lucide/svelte';
    import { onMount } from 'svelte';
    import { PROTOCOL_TABS } from '../../../../../expo-app/lib/protocol-tabs.ts';
    import { careSections } from '../../../../../expo-app/lib/protocol-care.ts';
    import { csrfHeaders } from '$lib/csrf.js';
    let { payload, protocolId = null, onclose } = $props();
    let result = $state(null);
    let error = $state('');
    let loading = $state(false);
    let week = $state(1);
    let tab = $state('vandaag');
    let phaseId = $state(null);
    let request = 0;
    const tabs = PROTOCOL_TABS.map((item) => ({ id: item.key, label: item.label }));
    let dialog;
    onMount(() => {
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.showModal();
        return () => { document.body.style.overflow = previousOverflow; };
    });
    const protocol = $derived(result?.protocol);
    const phase = $derived(protocol?.phases.find((p) => p.id === phaseId));
    $effect(() => { void load(week); });
    async function load(selectedWeek) {
        const version = ++request;
        loading = true;
        error = '';
        try {
            const headers = csrfHeaders();
            if (!headers['X-XSRF-TOKEN']) throw new Error('Je sessie is verlopen. Open de editor opnieuw voordat je de preview bekijkt.');
            const response = await fetch(protocolId ? `/admin/protocols/${protocolId}/preview` : '/admin/protocols/preview', {
                method: 'POST', headers: { ...headers, Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...payload, published: false, preview_week: selectedWeek }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'De preview kon niet worden geladen.');
            if (version === request) { result = data; phaseId = null; }
        } catch (e) { if (version === request) error = e.message || 'De preview kon niet worden geladen.'; }
        finally { if (version === request) loading = false; }
    }
</script>

<dialog bind:this={dialog} aria-label="Volledige klantpreview" oncancel={(e) => { e.preventDefault(); onclose(); }} class="fixed inset-0 m-0 h-full max-h-none w-full max-w-none overflow-hidden bg-transparent p-0 backdrop:bg-[#0B4A49]/65">
<div class="flex h-full justify-center p-0 sm:p-5">
    <button type="button" class="absolute inset-0" aria-label="Preview sluiten" onclick={onclose}></button>
    <section class="relative flex h-full w-full max-w-[430px] flex-col overflow-hidden bg-[#FBF8F3] text-[#1B2A2A] shadow-2xl sm:rounded-[28px]">
        <div class="flex items-center justify-between gap-3 border-b bg-white px-4 py-3">
            <button type="button" class="flex items-center gap-1 text-sm font-semibold" onclick={onclose}><ArrowLeft class="size-4" /> Terug naar bewerken</button>
            <button type="button" onclick={onclose} aria-label="Preview sluiten"><X class="size-5" /></button>
        </div>
        <div class="flex items-center justify-between gap-3 bg-[#EAFBF9] px-4 py-2 text-xs text-[#0E6F69]">
            <span>Preview · niet opgeslagen</span>
            <label>Bekijk week <select class="rounded border bg-white p-1" aria-label="Previewweek" bind:value={week} disabled={loading}>{#each Array.from({ length: Math.max(1, protocol?.totalWeeks ?? 1) }, (_, i) => i + 1) as n}<option value={n}>{n}</option>{/each}</select></label>
        </div>
        {#if error}<div role="alert" class="m-4 rounded-xl bg-red-50 p-4 text-sm text-red-800">{error}<button type="button" class="mt-3 block underline" onclick={() => load(week)}>Opnieuw proberen</button></div>{/if}
        {#if loading}<p role="status" class="p-4 text-sm">Preview laden…</p>{/if}
        {#if protocol && !error && !loading}
            <header class="px-5 py-4"><p class="text-xs text-[#1B2A2A]/55">{result.horse.name}</p><h2 class="mt-1 text-xl font-bold">{protocol.title}</h2><p class="mt-1 text-xs text-[#1B2A2A]/55">Week {protocol.currentWeek} van {protocol.totalWeeks}{protocol.phaseLabel ? ` · ${protocol.phaseLabel}` : ''}</p></header>
            <nav aria-label="Protocolonderdelen" class="flex border-b px-2">{#each tabs as item}<button type="button" class={`min-h-12 flex-1 border-b-2 text-[13px] font-semibold ${tab === item.id ? 'border-[#18BAB0] text-[#108A82]' : 'border-transparent text-[#1B2A2A]/65'}`} aria-pressed={tab === item.id} onclick={() => { tab = item.id; phaseId = null; }}>{item.label}</button>{/each}</nav>
            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 pb-8 pt-4">
                {#if phase}
                    <button type="button" class="text-sm text-[#108A82]" onclick={() => (phaseId = null)}>← Terug naar {tab === 'vandaag' ? 'Vandaag' : 'Kalender'}</button>
                    <h3 class="text-lg font-bold">{phase.title}</h3><p class="text-xs text-[#1B2A2A]/55">{phase.weekLabel} · {phase.statusLabel}</p>
                    {#if phase.state === 'locked'}<p class="text-xs text-[#A06A1B]">Voor de klant beschikbaar vanaf één week voor de start. In deze preview kun je de inhoud alvast controleren.</p>{/if}
                    {#if phase.description}<p class="whitespace-pre-line text-sm leading-6">{phase.description}</p>{/if}
                    {#each phase.supplements as item}{@render supplement(item)}{/each}
                {:else if tab === 'vandaag'}
                    <div class="rounded-[22px] bg-[#105C5B] p-5 text-white"><p class="text-xs text-[#99E8DF]">{protocol.statusLabel}</p><h3 class="mt-2 text-xl font-bold">Vandaag voor {result.horse.name}</h3><p class="mt-2 text-xs">{protocol.todayLabel}</p></div>
                    {#each protocol.today.items as item}{@render supplement(item)}{:else}<p class="text-sm text-[#1B2A2A]/55">Geen kruiden of supplementen ingepland in deze week.</p>{/each}
                    {@render phases()}
                {:else if tab === 'kalender'}
                    <h3 class="font-bold">{protocol.calendar.label}</h3>
                    <div class="grid grid-cols-7 gap-1 text-center text-xs">{#each ['ma','di','wo','do','vr','za','zo'] as d}<span class="p-2 text-[#1B2A2A]/50">{d}</span>{/each}{#each protocol.calendar.cells as day}<span class={`rounded-lg p-2 ${day?.isToday ? 'bg-[#18BAB0] font-bold text-white' : ''}`}>{day?.day ?? ''}</span>{/each}</div>
                    {@render phases()}
                {:else if tab === 'voeding'}
                    <section class="rounded-[22px] bg-[#105C5B] p-5 text-white"><div class="flex items-center gap-2 text-xs uppercase text-[#99E8DF]"><Leaf class="size-4" /> Ruwvoer, de basis</div>{#if protocol.nutrition.roughage.rangeLabel}<p class="mt-3 text-2xl font-bold">{protocol.nutrition.roughage.rangeLabel} <span class="text-xs">per 24 uur</span></p>{/if}<p class="mt-2 whitespace-pre-line text-[13px] leading-[21px] text-white/85">{protocol.nutrition.roughage.description}</p><div class="mt-4 flex gap-2 border-t border-white/15 pt-3">{#each [['Suiker', protocol.nutrition.roughage.sugar], ['Eiwit', protocol.nutrition.roughage.protein]] as entry}<div class="flex-1 rounded-xl bg-white/10 p-3"><p class="text-[10px] uppercase text-white/65">{entry[0]}</p><p class="mt-1 text-sm font-bold">{entry[1]}</p></div>{/each}</div></section>
                    {@render libraryLink('Hooi laten analyseren of zelf testen', 'Bekijk hoe je een monster neemt en de uitslag beoordeelt.', protocol.nutrition.hayLibraryItem)}
                    {#if protocol.nutrition.feeds.length}<section class="space-y-4 rounded-[22px] border bg-white p-5"><h3 class="font-bold">Actuele voerproducten</h3>{#each protocol.nutrition.feeds as feed}<div class="flex items-start gap-2">{#if feed.status === 'continue'}<Check class="mt-1 size-4 shrink-0 text-[#18BAB0]" />{:else}<X class="mt-1 size-4 shrink-0 text-[#CB655D]" />{/if}<div><p class="text-sm font-semibold">{feed.name}{feed.dosage ? ` · ${feed.dosage}` : ''}</p><p class={`mt-1 text-xs font-semibold ${feed.status === 'continue' ? 'text-[#108A82]' : 'text-[#B45E56]'}`}>{feed.status === 'continue' ? 'Doorgaan' : 'Stoppen'}</p>{#if feed.note}<p class="mt-1 whitespace-pre-line text-xs leading-5 text-[#1B2A2A]/65">{feed.note}</p>{/if}</div></div>{/each}</section>{/if}
                    {#each protocol.nutrition.advice ?? [] as advice}{@render adviceCard(advice)}{/each}
                    {#if protocol.nutrition.water.types.length}<section class="rounded-[22px] border bg-white p-5"><h3 class="font-bold">Water</h3><p class="mt-2 text-sm">{protocol.nutrition.water.types.join(' · ')}</p>{#if protocol.nutrition.water.advice}<p class="mt-2 text-sm text-[#1B2A2A]/65">{protocol.nutrition.water.advice}</p>{/if}</section>{/if}
                    {#if protocol.nutrition.water.needsAnalysis}{@render libraryLink('Water laten analyseren', 'Bekijk de uitleg in de bibliotheek.', protocol.nutrition.waterLibraryItem)}{/if}
                {:else if tab === 'zorg'}
                    {#each careSections(protocol) as group}<h3 class="pt-2 text-sm font-bold">{group.title}</h3>{#each group.items as advice}{@render adviceCard(advice)}{/each}{/each}
                    {#if !careSections(protocol).length}<p class="text-sm text-[#1B2A2A]/55">Er zijn voor dit paard geen actieve zorgadviezen.</p>{/if}
                {:else if tab === 'analyse'}
                    {#if protocol.analysis}<section class="rounded-[22px] bg-[#EAFBF9] p-4"><h3 class="text-xs font-semibold uppercase tracking-wide text-[#0E6F69]">Persoonlijke analyse</h3><p class="mt-2 whitespace-pre-line text-sm leading-6">{protocol.analysis.summary}</p></section>{#each protocol.analysis.priorities as point}<section class="rounded-2xl bg-white p-4"><h3 class="text-sm font-semibold">{point.title}</h3><p class="mt-1 text-sm">{point.body}</p></section>{/each}{#if protocol.analysis.observations.length}<h3 class="text-xs font-semibold uppercase">Waar letten we op?</h3><ul class="list-disc space-y-2 pl-4 text-sm leading-6">{#each protocol.analysis.observations as observation}<li>{observation}</li>{/each}</ul>{/if}{:else}<p class="text-sm text-[#1B2A2A]/55">De persoonlijke analyse is nog niet ingevuld voor dit paard.</p>{/if}
                {/if}
            </div>
        {/if}
    </section>
</div>
</dialog>

{#snippet supplement(item)}
    <article class="rounded-2xl border border-[#1B2A2A]/10 bg-white p-4"><div class="flex items-start justify-between gap-3"><h4 class="text-sm font-semibold">{item.name}</h4><span class="max-w-[40%] text-[13px] font-bold text-[#108A82]">{item.dosage ?? 'Dosering niet ingesteld'}</span></div>{#if item.frequencyLabel}<p class="mt-1 text-xs text-[#1B2A2A]/55">{item.frequencyLabel}</p>{/if}{#if item.weekNumbers?.length}<p class="mt-1 text-xs text-[#1B2A2A]/55">Protocolweek {item.weekNumbers.join(', ')}</p>{/if}{#if item.description}<p class="mt-1 whitespace-pre-line text-xs leading-[18px] text-[#1B2A2A]/55">{item.description}</p>{/if}{#if item.instructions}<p class="mt-2 whitespace-pre-line text-xs leading-[19px] text-[#1B2A2A]/75">{item.instructions}</p>{/if}</article>
{/snippet}
{#snippet phases()}
    <h3 class="pt-3 text-sm font-bold">Verloop van je protocol</h3>
    {#each protocol.phases as item}<button type="button" onclick={() => (phaseId = item.id)} class={`flex w-full items-center gap-2 rounded-2xl border bg-white p-4 text-left ${item.state === 'active' ? 'border-[#18BAB0]' : 'border-[#1B2A2A]/10'}`}><div class="flex-1"><h4 class="text-sm font-semibold">{item.title}</h4><p class="mt-1 text-xs text-[#1B2A2A]/55">{item.weekLabel} · {item.durationLabel}</p></div><span class="max-w-[40%] rounded-full bg-[#EAFBF9] px-2 py-1 text-[10px] text-[#108A82]">{item.statusLabel}</span><ChevronRight class="size-4 shrink-0" /></button>{/each}
{/snippet}
{#snippet adviceCard(advice)}
    <article class="rounded-2xl border border-[#1B2A2A]/10 bg-white p-4"><h4 class="text-sm font-semibold">{advice.title}</h4>{#if advice.action}<p class="mt-1 text-xs font-semibold text-[#108A82]">{advice.action === 'avoid' ? 'Vermijden' : 'Doen'}</p>{/if}{#if advice.description}<p class="mt-2 whitespace-pre-line text-sm leading-6 text-[#1B2A2A]/70">{advice.description}</p>{/if}{#if advice.frequency}<p class="mt-2 text-xs">{advice.frequency}</p>{/if}{#if advice.note}<p class="mt-2 whitespace-pre-line text-sm">{advice.note}</p>{/if}{#if advice.url}<a class="mt-3 block text-sm text-[#108A82] underline" href={advice.url} target="_blank" rel="noreferrer">{advice.ctaLabel || 'Meer informatie'}</a>{/if}</article>
{/snippet}

{#snippet libraryLink(title, description, item)}
    <div class="flex items-center gap-3 rounded-[18px] border border-[#1B2A2A]/10 bg-white p-4">{#if item?.heroImageUrl}<img src={item.heroImageUrl} alt="" class="h-16 w-16 rounded-lg object-cover" />{/if}<div class="flex-1"><p class="text-[13px] font-semibold">{title}</p><p class="mt-1 text-[11px] leading-4 text-[#1B2A2A]/55">{description}</p></div><ChevronRight class="size-4" /></div>
{/snippet}
