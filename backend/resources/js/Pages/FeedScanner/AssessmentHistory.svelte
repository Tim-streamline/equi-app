<script>
    import { router, useForm } from '@inertiajs/svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { Button, Input, Textarea, Badge } from '$lib/components/ui';

    let { product, canPublish, profiles, errors = {} } = $props();
    let calculating = $state(false);
    let correcting = $state(null);
    let correctionOpen = $state(false);
    const form = useForm({ score: '', reason: '' });
    const format = value => value == null ? '—' : Number(value).toLocaleString('nl-NL', { maximumFractionDigits: 8 });
    function calculate(version) {
        router.post(`/admin/feed-scanner/products/${product.id}/assess`, { version_id: version.id }, { preserveScroll: true, onStart: () => calculating = true, onFinish: () => calculating = false });
    }
    function correct(assessment) {
        correcting = assessment;
        $form.defaults({ score: assessment.overrides[0]?.corrected_score ?? assessment.automatic_score ?? '', reason: '' });
        $form.reset(); $form.clearErrors(); correctionOpen = true;
    }
    function save(e) {
        e.preventDefault();
        $form.post(`/admin/feed-scanner/assessments/${correcting.id}/correct`, { preserveScroll: true, onSuccess: () => correctionOpen = false });
    }
</script>

<section class="space-y-4" aria-label="Productbeoordelingen">
    <h3 class="text-lg font-semibold">Beoordelingen</h3>
    <p class="text-sm text-muted-foreground">De motor berekent de vastgelegde plafonds. De overige puntenweging is nog open. Een handmatige beoordeling blijft afzonderlijk herkenbaar.</p>
    {#each Object.values(errors) as error}<p role="alert" class="text-sm text-destructive">{error}</p>{/each}
    {#each product.versions as version, versionIndex (version.id)}
        {@const profile = profiles.find(p => p.id === version.profile_id)}
        <div class="space-y-3 rounded-lg border p-4">
            <div class="flex flex-wrap items-center justify-between gap-2"><h4 class="font-semibold">Productversie {version.number}</h4><Button size="sm" variant="outline" disabled={calculating || !profile?.active_rule_version_id || product.catalog_status === 'archived'} onclick={() => calculate(version)}>Berekenen met actieve regels</Button></div>
            {#if !version.profile_id}<p class="text-sm text-muted-foreground">Kies eerst een categorie door een nieuw productconcept op te slaan.</p>{:else if !profile?.active_rule_version_id}<p class="text-sm text-muted-foreground"><a href="/admin/feed-scanner/settings" class="underline">Activeer eerst de scoreplafonds</a> voor dit profiel.</p>{/if}
            {#each version.assessments ?? [] as assessment, index (assessment.id)}
                {@const manual = assessment.overrides[0]}
                {@const isCurrent = versionIndex === 0 && index === 0 && assessment.rule_version_id === profile?.active_rule_version_id}
                <article class="space-y-3 border-t pt-4">
                    <div class="flex flex-wrap items-center gap-2"><Badge variant="muted">Regelversie {assessment.rule_version?.number ?? '—'}</Badge>{#if assessment.is_preview}<Badge variant="muted">Voorlopig · product nog niet goedgekeurd bij berekening</Badge>{/if}{#if assessment.rule_version_id !== profile?.active_rule_version_id}<Badge variant="muted">Historisch beleid</Badge>{/if}<span class="text-xs text-muted-foreground">{new Date(assessment.created_at).toLocaleString('nl-NL')}</span></div>
                    {#if manual}<p class="text-lg font-semibold">Handmatige beoordeling: {format(manual.corrected_score)}/10</p>{:else if assessment.automatic_score != null}<p class="text-lg font-semibold">Automatische score: {format(assessment.automatic_score)}/10</p>{:else}<p class="font-semibold">Nog geen automatische eindscore</p>{/if}
                    <p class="text-sm">Scoreplafond: {assessment.score_cap == null ? 'geen vastgesteld plafond vanuit de huidige regels' : `${format(assessment.score_cap)}/10`}</p>
                    {#each assessment.result.findings as finding}<p class="text-sm">{finding.message}</p>{/each}
                    {#each assessment.result.applied_caps as cap}<p class="text-sm font-medium">{cap.message}</p>{/each}
                    {#if assessment.result.missing_data.length}<div class="rounded-lg bg-muted p-3 text-sm"><p class="font-medium">Nog nodig voor een volledige beoordeling:</p><ul class="mt-2 list-disc space-y-1 pl-5">{#each assessment.result.missing_data as missing}<li>{missing}</li>{/each}</ul></div>{/if}
                    {#if assessment.automatic_score == null}<p class="text-sm text-muted-foreground">De overige puntenweging is nog niet gekalibreerd. Een maximum van 4 of 5 is geen toegekende eindscore.</p>{/if}
                    {#if assessment.changes}<div class="rounded-lg border p-3 text-sm"><p class="font-medium">Verschil met de vorige berekening</p><p>Automatische score: {format(assessment.changes.previous_automatic_score)} → {format(assessment.automatic_score)}</p><p>Scoreplafond: {format(assessment.changes.previous_score_cap)} → {format(assessment.score_cap)}</p>{#if assessment.changes.previous_rule_version_id !== assessment.rule_version_id}<p>Een andere regelversie is gebruikt.</p>{/if}{#if assessment.changes.previous_product_version_id !== assessment.product_version_id}<p>Een andere productversie is gebruikt.</p>{/if}{#if assessment.changes.previous_manual_score != null}<p>Een eerdere handmatige score is niet automatisch overgenomen.</p>{/if}</div>{/if}
                    {#if canPublish && isCurrent && product.catalog_status !== 'archived' && (assessment.automatic_score != null || !assessment.result.missing_data.length)}<Button size="sm" variant="outline" onclick={() => correct(assessment)}>{assessment.automatic_score == null ? 'Handmatig beoordelen' : 'Score corrigeren'}</Button>{/if}
                    {#each assessment.overrides as override}<div class="border-l-2 pl-3 text-sm"><p class="font-medium">Handmatig: {format(override.corrected_score)}/10 · {override.actor_name}</p><p class="whitespace-pre-wrap">{override.reason}</p><p class="text-xs text-muted-foreground">{new Date(override.created_at).toLocaleString('nl-NL')} · oorspronkelijke automatische score: {format(assessment.automatic_score)}</p></div>{/each}
                    <details class="text-sm"><summary class="cursor-pointer text-muted-foreground">Analyse en omrekening bekijken</summary><div class="mt-2 space-y-2">{#each assessment.result.normalization as entry}<p>{entry.code === 'sugar' ? 'Suiker' : 'Zetmeel'}: {entry.original.value} {entry.original.unit} · {entry.original.basis === 'dry_matter' ? `droge-stofbasis, omgerekend met ${entry.dry_matter_percent}% droge stof` : 'productbasis'} → {entry.product_basis.lower}–{entry.product_basis.upper}% op productbasis.</p>{/each}</div></details>
                </article>
            {:else}<p class="text-sm text-muted-foreground">Nog geen berekening voor deze productversie.</p>{/each}
        </div>
    {/each}
</section>
<Modal bind:open={correctionOpen} title="Handmatige beoordeling">
    <form id="feed-correction" onsubmit={save} class="space-y-4">
        {#each Object.values($form.errors) as error}<p role="alert" class="text-sm text-destructive">{error}</p>{/each}
        <p class="text-sm text-muted-foreground">De automatische uitkomst blijft bewaard. Een geldend plafond geldt ook voor deze handmatige score.</p>
        <Field label="Score /10"><Input type="number" min="0" max={correcting?.score_cap ?? 10} step="0.01" bind:value={$form.score} required /></Field>
        <Field label="Motivatie (verplicht)"><Textarea rows={3} bind:value={$form.reason} required /></Field>
    </form>
    {#snippet footer()}<Button variant="outline" onclick={() => correctionOpen = false}>Annuleren</Button><Button form="feed-correction" type="submit" disabled={$form.processing}>Beoordeling opslaan</Button>{/snippet}
</Modal>
