<script>
    import { router, useForm } from '@inertiajs/svelte';
    import Field from '$lib/components/Field.svelte';
    import { Button, Input, Textarea } from '$lib/components/ui';
    let { question } = $props();
    const form = useForm({ label: question.label, order: question.order, active: question.active });
</script>

<form class="space-y-4 rounded-xl border bg-card p-5" onsubmit={event => { event.preventDefault(); $form.put(`/admin/evaluation-questions/${question.id}`, { preserveScroll: true }); }}>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <strong>{question.active ? 'Actieve vraag' : 'Verwijderde vraag'}</strong>
        <span class="font-mono text-xs text-muted-foreground">{question.id}</span>
    </div>
    <Field label="Vraag" error={$form.errors.label}><Textarea rows="3" bind:value={$form.label} /></Field>
    <div class="flex flex-wrap items-end gap-5">
        <Field label="Volgorde" error={$form.errors.order}><Input type="number" min="0" bind:value={$form.order} /></Field>
        <label class="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.active} />Actief in nieuwe evaluaties</label>
    </div>
    {#if $form.errors.active}<p class="text-sm text-destructive">{$form.errors.active}</p>{/if}
    <div class="flex gap-3">
        <Button type="submit" disabled={$form.processing}>Opslaan</Button>
        {#if question.active}<Button type="button" variant="outline" onclick={() => {
            if (confirm('Deze evaluatievraag verwijderen uit nieuwe formulieren? Eerdere evaluaties blijven bewaard.')) router.delete(`/admin/evaluation-questions/${question.id}`, { preserveScroll: true });
        }}>Verwijderen</Button>{/if}
    </div>
</form>
