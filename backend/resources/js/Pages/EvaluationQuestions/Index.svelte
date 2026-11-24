<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Field from '$lib/components/Field.svelte';
    import EvaluationQuestionEditor from '$lib/components/EvaluationQuestionEditor.svelte';
    import { Button, Input, Textarea } from '$lib/components/ui';
    import { useForm } from '@inertiajs/svelte';
    let { questions } = $props();
    // The server sorts by order, so the last question has the highest.
    const nextOrder = () => (questions.at(-1)?.order ?? -1) + 1;
    const form = useForm({ label: '', order: nextOrder() });
</script>

<AdminLayout title="Evaluatievragen">
    <PageHeader title="Evaluatievragen" description="Beheer de vragen aan het einde van een EquiBoost herstelplan."/>
    <p class="mb-6 max-w-3xl text-sm leading-6 text-muted-foreground">Wijzigingen gelden voor nieuwe evaluatieformulieren. Zodra een klant een concept opslaat, blijven die vragen bij zijn evaluatie bewaard. Zo behouden bestaande antwoorden hun betekenis. Verwijderde vragen kun je opnieuw activeren. Verstuurde evaluaties vind je bij het betreffende paard en protocol.</p>
    <div class="max-w-3xl space-y-4">
        {#each questions as question (question.id)}<EvaluationQuestionEditor {question}/>{/each}
        <form class="space-y-4 rounded-xl border bg-card p-5" onsubmit={event => { event.preventDefault(); $form.post('/admin/evaluation-questions', { preserveScroll: true, onSuccess: () => { $form.reset('label'); $form.order = nextOrder(); } }); }}>
            <h2 class="font-semibold">Nieuwe evaluatievraag</h2>
            <Field label="Vraag" error={$form.errors.label}><Textarea rows="3" bind:value={$form.label}/></Field>
            <Field label="Volgorde" error={$form.errors.order}><Input type="number" min="0" bind:value={$form.order}/></Field>
            <Button type="submit" disabled={$form.processing}>Vraag toevoegen</Button>
        </form>
    </div>
</AdminLayout>
