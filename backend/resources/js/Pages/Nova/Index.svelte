<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Input, Textarea } from '$lib/components/ui';
    import { formatDateTime } from '$lib/utils.js';
    import { Plus, Pencil, Trash2, GripVertical } from '@lucide/svelte';

    let { replies, sessionStats } = $props();

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

<AdminLayout title="Nova chat">
    <PageHeader title="Nova fallback replies" description={`${sessionStats.sessions} chat sessions stored`}>
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
