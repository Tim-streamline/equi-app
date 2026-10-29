<script>
    import { useForm } from '@inertiajs/svelte';
    import { Button, Textarea } from '$lib/components/ui';
    let { prompts = [], canEdit = false } = $props();
    let selected = $state(null);
    const form = useForm({ prompt: '', version: 1 });
    const titles = { label: 'Volledig etiket / geplakte tekst', composition: 'Ingrediënten en toevoegingen', analysis: 'Voedingswaarden', packaging: 'Voorkant / productidentiteit' };
    function edit(prompt) { selected = prompt.type; $form.prompt = prompt.prompt; $form.version = prompt.version; $form.clearErrors(); }
</script>

<section aria-label="Analyseprompts" class="my-6 space-y-4 rounded-xl border bg-card p-5">
    <h2 class="text-lg font-semibold">Fotoanalyse · OCR en Luna</h2>
    <p class="text-sm text-muted-foreground">Elke foto wordt afzonderlijk gelezen door Tesseract en GPT-6 Luna. Luna levert de automatische productgegevens. Tesseract gebruikt geen prompt. Wijzigingen hieronder gelden voor nieuwe analyses; eerdere resultaten bewaren hun gebruikte prompt.</p>
    {#each prompts as prompt}
        <div class="space-y-3 border-t pt-4">
            <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-medium">{titles[prompt.type]} · versie {prompt.version}</h3>{#if canEdit && selected !== prompt.type}<Button variant="outline" size="sm" onclick={() => edit(prompt)}>Prompt bewerken</Button>{/if}</div>
            {#if selected === prompt.type}
                <form class="space-y-3" onsubmit={e => { e.preventDefault(); $form.put(`/admin/feed-scanner/analysis-prompts/${prompt.type}`, { preserveScroll: true, onSuccess: () => selected = null }); }}>
                    <Textarea aria-label={`Analyseprompt ${titles[prompt.type]}`} bind:value={$form.prompt} rows={12} required maxlength={20000} />
                    {#each Object.values($form.errors) as error}<p role="alert" class="text-sm text-destructive">{error}</p>{/each}
                    <div class="flex gap-2"><Button type="submit" disabled={$form.processing}>Prompt opslaan</Button><Button type="button" variant="outline" onclick={() => selected = null}>Annuleren</Button></div>
                </form>
            {:else}<p class="whitespace-pre-wrap text-sm text-muted-foreground">{prompt.prompt}</p>{/if}
        </div>
    {/each}
</section>
