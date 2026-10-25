<script>
    let { profiles } = $props();
    const selected = item => item.versions.find(v => v.id === item.active_rule_version_id) ?? item.versions[0];
    const base = $derived(profiles.find(p => p.code === 'standard') ?? profiles[0]);
    const version = $derived(base ? selected(base) : null);
    const data = $derived(version?.data);
    const keys = ['principles', 'explanation', 'sugar_starch_challenged', 'sugar_starch_healthy', 'sugar_starch_caps', 'organic_selenium_cap', 'unknown_added_selenium_cap', 'exceptions'];
    const labels = { principles: 'Voedingsprincipes', explanation: 'Toelichting', sugar_starch_challenged: 'Suiker + zetmeel bij gezondheidsuitdagingen', sugar_starch_healthy: 'Suiker + zetmeel voor gezonde paarden', sugar_starch_caps: 'Scoreplafonds suiker + zetmeel', organic_selenium_cap: 'Plafond organisch selenium', unknown_added_selenium_cap: 'Plafond toegevoegd selenium, vorm onbekend', exceptions: 'Ingrediëntenuitzonderingen' };
    function canonical(value) {
        if (Array.isArray(value)) return value.map(canonical);
        if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]));
        return typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value)) ? Number(value) : value;
    }
    function different(item) { return keys.filter(key => JSON.stringify(canonical(selected(item)?.data?.[key] ?? null)) !== JSON.stringify(canonical(data?.[key] ?? null))); }
</script>
<section class="rounded-xl border bg-card p-5">
    <h2 class="text-lg font-semibold">Algemene voedingsprincipes en scoreplafonds</h2>
    {#if data}
        <p class="mt-1 text-xs text-muted-foreground">{version.id === base.active_rule_version_id ? 'Actief beleid' : 'Concept · nog niet actief'} · {base.name}</p>
        {#if data.principles}<p class="mt-4 whitespace-pre-wrap text-sm">{data.principles}</p>{/if}
        <div class="mt-4 space-y-2 text-sm">
            <p>Suiker + zetmeel samen: maximaal {data.sugar_starch_challenged}% bij gezondheidsuitdagingen en {data.sugar_starch_healthy}% voor gezonde paarden.</p>
            {#each data.sugar_starch_caps ?? [] as tier}<p>{tier.inclusive ? 'Vanaf' : 'Boven'} {tier.threshold}% suiker + zetmeel: maximaal {tier.max_score}/10.</p>{/each}
            <p>Bevestigd organisch selenium: maximaal {data.organic_selenium_cap}/10.</p>
            <p>Bevestigd toegevoegd selenium met onbekende vorm: maximaal {data.unknown_added_selenium_cap}/10.</p>
            <p class="text-muted-foreground">Alleen selenium totaal activeert geen plafond. Bevestigd anorganisch selenium heeft geen seleniumplafond. Een plafond is een maximum, geen toegekend rapportcijfer.</p>
        </div>
        {#if data.explanation}<p class="mt-4 whitespace-pre-wrap text-sm text-muted-foreground">{data.explanation}</p>{/if}
        {#each profiles.filter(p => p.id !== base.id) as item}
            {@const differences = different(item)}
            {#if differences.length}<div class="mt-5 space-y-2 border-t pt-4 text-sm"><h3 class="font-semibold">Afwijkingen voor {item.name}</h3>
                <p class="text-xs text-muted-foreground">{selected(item)?.id === item.active_rule_version_id ? 'Actief beleid' : 'Concept · nog niet actief'}</p>
                {#each differences as key}<div><span class="font-medium">{labels[key]}: </span>{#if key === 'sugar_starch_caps'}{#each selected(item)?.data?.[key] ?? [] as tier}<p>{tier.inclusive ? 'Vanaf' : 'Boven'} {tier.threshold}%: maximaal {tier.max_score}/10.</p>{/each}{:else if key === 'exceptions'}<p>{selected(item)?.data?.exceptions?.length ?? 0} vastgelegde uitzonderingen; bekijk de voorwaarden bij Geavanceerde instellingen.</p>{:else}<span>{selected(item)?.data?.[key] ?? 'Niet ingevuld'}</span>{/if}</div>{/each}
            </div>{/if}
        {/each}
    {:else}<p class="mt-3 text-sm text-muted-foreground">Er is nog geen voedingsbeleid vastgelegd.</p>{/if}
</section>
