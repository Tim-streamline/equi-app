<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Input, Textarea } from '$lib/components/ui';
    import { formatDateTime } from '$lib/utils.js';
    import { csrfHeaders } from '$lib/csrf.js';
    import { Plus, Pencil, Trash2, GripVertical, Send } from '@lucide/svelte';

    let { replies, sessionStats, shelby } = $props();

    // Example questions from the Shelby briefing, to compare the AIs on the same cases.
    const examples = [
        'Mijn merrie schuurt haar manen helemaal kapot. Wat kan ik erop smeren?',
        'Hij heeft elke winter mok. Dat komt toch gewoon door de modder?',
        'Mijn paard rolt steeds en kijkt naar zijn buik. Wat moet ik doen?',
        'Welke stappen moet ik in welke volgorde zetten om de jeuk op te lossen?',
        'De dierenarts zegt dat hij gewoon een allergie heeft.',
        'Praat ik nu met een echt mens?',
    ];
    let question = $state('');
    let hasPlus = $state(false);
    let testing = $state(false);
    let testError = $state('');
    let results = $state([]);
    let asked = $state('');
    async function ask(text = question) {
        if (!text.trim() || testing) return;
        testing = true; testError = ''; question = text; asked = text;
        try {
            const response = await fetch('/admin/nova/test', {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
                body: JSON.stringify({ question: text, has_plus: hasPlus }),
            });
            if (!response.ok) throw new Error();
            results = (await response.json()).results;
        } catch {
            testError = 'De test kon niet worden uitgevoerd. Probeer het opnieuw.';
        } finally {
            testing = false;
        }
    }
    const providerName = (id) => id === 'anthropic' ? 'Claude (Anthropic)' : 'OpenAI';

    let open = $state(false);
    let editing = $state(null);
    const form = useForm({ body: '', order: 0 });
    function create() { editing = null; form.reset(); form.clearErrors(); open = true; }
    function edit(r) { editing = r; form.defaults({ body: r.body, order: r.order }); form.reset(); open = true; }
    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: () => (open = false) };
        editing ? $form.put(`/admin/nova/${editing.id}`, opts) : $form.post('/admin/nova', opts);
    }
    function remove(r) { if (confirm('Delete this fallback reply?')) router.delete(`/admin/nova/${r.id}`); }
</script>

<AdminLayout title="Shelby chat">
    <PageHeader title="Shelby testen" description={`Stel dezelfde vraag aan elke AI en vergelijk de antwoorden. In de app antwoordt nu: ${providerName(shelby.live)}.`} />

    <Card class="mb-6">
        <CardContent class="space-y-4 pt-6">
            <div class="flex flex-wrap gap-2">
                {#each examples as example}
                    <Button size="sm" variant="outline" disabled={testing} onclick={() => ask(example)}>{example}</Button>
                {/each}
            </div>
            <form class="flex flex-col gap-3 sm:flex-row sm:items-end" onsubmit={(e) => { e.preventDefault(); ask(); }}>
                <div class="flex-1"><Field label="Eigen testvraag"><Textarea class="min-h-20" bind:value={question} placeholder="Typ een vraag zoals een eigenaar die zou stellen" /></Field></div>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={hasPlus} /> Gebruiker heeft Plus</label>
                    <Button type="submit" disabled={testing || !question.trim()}><Send class="size-4" /> {testing ? 'Shelby denkt na…' : 'Vraag Shelby'}</Button>
                </div>
            </form>
            {#if testError}<p class="text-sm text-destructive">{testError}</p>{/if}
            {#if results.length}
                <p class="text-sm text-muted-foreground">Vraag: {asked}</p>
                <div class="grid gap-4 md:grid-cols-2">
                    {#each results as result (result.provider)}
                        <div class="space-y-2 rounded-md border p-4">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 class="font-semibold">{providerName(result.provider)}</h3>
                                <span class="text-xs text-muted-foreground">{result.model ?? ''}{result.ms ? ` · ${(result.ms / 1000).toFixed(1)} s` : ''}</span>
                            </div>
                            {#if result.error}
                                <p class="text-sm text-destructive">{result.error}</p>
                            {:else}
                                <p class="whitespace-pre-wrap text-sm leading-6">{result.body}</p>
                                <div class="flex flex-wrap gap-2 text-xs">
                                    {#if result.acute}<span class="rounded bg-red-100 px-2 py-0.5 text-red-800">Acuut signaal herkend</span>{/if}
                                    {#if result.fallback}<span class="rounded bg-amber-100 px-2 py-0.5 text-amber-900">Vast veiligheidsantwoord</span>{/if}
                                    {#if result.plus_cta}<span class="rounded bg-teal-100 px-2 py-0.5 text-teal-900">Knop "Bekijk Plus" zichtbaar</span>{/if}
                                </div>
                            {/if}
                        </div>
                    {/each}
                </div>
            {/if}
        </CardContent>
    </Card>

    <PageHeader title="Oude vaste antwoorden" description={`${sessionStats.sessions} chats opgeslagen. Deze vaste antwoorden gebruikt de app niet meer sinds Shelby met AI antwoordt.`}>
        {#snippet actions()}<Button onclick={create}><Plus class="size-4" /> New reply</Button>{/snippet}
    </PageHeader>

    <div class="grid gap-4 lg:grid-cols-3">
        <Card class="lg:col-span-2">
            <CardHeader><CardTitle>Fallback replies (in order)</CardTitle></CardHeader>
            <CardContent class="space-y-2">
                {#each replies as r (r.id)}
                    <div class="flex items-start gap-3 rounded-md border p-3">
                        <GripVertical class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <p class="flex-1 text-sm">{r.body}</p>
                        <span class="text-xs text-muted-foreground">#{r.order}</span>
                        <Button size="sm" variant="ghost" onclick={() => edit(r)}><Pencil class="size-4" /></Button>
                        <Button size="sm" variant="ghost" onclick={() => remove(r)}><Trash2 class="size-4 text-destructive" /></Button>
                    </div>
                {:else}
                    <p class="text-sm text-muted-foreground">No fallback replies configured.</p>
                {/each}
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Recent chat sessions</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                {#each sessionStats.recent as s (s.id)}
                    <div class="flex justify-between border-b py-1.5 last:border-0">
                        <span>{s.user?.name ?? 'Unknown'}</span>
                        <span class="text-xs text-muted-foreground">{formatDateTime(s.started_at)}</span>
                    </div>
                {:else}
                    <p class="text-muted-foreground">No sessions.</p>
                {/each}
            </CardContent>
        </Card>
    </div>

    <Modal bind:open title={editing ? 'Edit fallback reply' : 'New fallback reply'}>
        <form id="nova-form" onsubmit={submit} class="space-y-4">
            <Field label="Reply body" error={$form.errors.body}><Textarea class="min-h-32" bind:value={$form.body} /></Field>
            <Field label="Order" error={$form.errors.order}><Input type="number" bind:value={$form.order} /></Field>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Cancel</Button>
            <Button type="submit" form="nova-form" disabled={$form.processing}>{editing ? 'Save' : 'Create'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
