<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Textarea, Select, Badge } from '$lib/components/ui';
    import { Plus, Pencil, Trash2 } from '@lucide/svelte';

    let { tips, libraryItems } = $props();
    let open = $state(false);
    let editing = $state(null);
    const form = useForm({ month: '', month_order: 1, body: '', cta_item_id: '', active: false, active_from: '', active_to: '' });
    function create() { editing = null; form.reset(); form.clearErrors(); open = true; }
    function edit(t) {
        editing = t;
        form.defaults({ ...t, cta_item_id: t.cta_item_id ?? '', active_from: t.active_from ?? '', active_to: t.active_to ?? '' });
        form.reset(); open = true;
    }
    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: () => (open = false) };
        editing ? $form.put(`/admin/seasonal-tips/${editing.id}`, opts) : $form.post('/admin/seasonal-tips', opts);
    }
    function remove(t) { if (confirm('Delete this tip?')) router.delete(`/admin/seasonal-tips/${t.id}`); }
</script>

<AdminLayout title="Seasonal tips">
    <PageHeader title="Seasonal tips" description="Monthly home-screen tips with optional CTA">
        {#snippet actions()}<Button onclick={create}><Plus class="size-4" /> New tip</Button>{/snippet}
    </PageHeader>
    <div class="grid gap-4 md:grid-cols-2">
        {#each tips as t (t.id)}
            <Card>
                <CardContent class="p-5">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="font-medium capitalize">{t.month} <span class="text-muted-foreground">#{t.month_order}</span></div>
                        {#if t.active}<Badge variant="success">active</Badge>{:else}<Badge variant="muted">inactive</Badge>{/if}
                    </div>
                    <p class="text-sm text-muted-foreground">{t.body}</p>
                    {#if t.cta_item}<div class="mt-2 text-xs text-primary">CTA → {t.cta_item.title}</div>{/if}
                    <div class="mt-3 flex gap-2">
                        <Button size="sm" variant="ghost" onclick={() => edit(t)}><Pencil class="size-4" /></Button>
                        <Button size="sm" variant="ghost" onclick={() => remove(t)}><Trash2 class="size-4 text-destructive" /></Button>
                    </div>
                </CardContent>
            </Card>
        {/each}
    </div>

    <Modal bind:open title={editing ? 'Edit tip' : 'New tip'}>
        <form id="st-form" onsubmit={submit} class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <Field label="Month label" error={$form.errors.month}><Input bind:value={$form.month} placeholder="mei" /></Field>
                <Field label="Month order (1-12)" error={$form.errors.month_order}><Input type="number" min="1" max="12" bind:value={$form.month_order} /></Field>
            </div>
            <Field label="Body" error={$form.errors.body}><Textarea bind:value={$form.body} /></Field>
            <Field label="CTA library item" error={$form.errors.cta_item_id}>
                <Select bind:value={$form.cta_item_id} placeholder="None" options={libraryItems.map((i) => ({ value: i.id, label: i.title }))} />
            </Field>
            <div class="grid grid-cols-2 gap-4">
                <Field label="Active from"><Input type="date" bind:value={$form.active_from} /></Field>
                <Field label="Active to"><Input type="date" bind:value={$form.active_to} /></Field>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.active} class="size-4 rounded border-input" /> Active</label>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Cancel</Button>
            <Button type="submit" form="st-form" disabled={$form.processing}>{editing ? 'Save' : 'Create'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
