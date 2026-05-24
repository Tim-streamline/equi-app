<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { Plus, Pencil, Trash2 } from '@lucide/svelte';

    let { topics } = $props();
    let open = $state(false);
    let editing = $state(null);
    const form = useForm({ title: '', slug: '', icon: '', description: '', order: 0 });
    function create() { editing = null; form.reset(); form.clearErrors(); open = true; }
    function edit(t) { editing = t; form.defaults({ ...t }); form.reset(); open = true; }
    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: () => (open = false) };
        editing ? $form.put(`/admin/focus-topics/${editing.id}`, opts) : $form.post('/admin/focus-topics', opts);
    }
    function remove(t) { if (confirm(`Delete ${t.title}?`)) router.delete(`/admin/focus-topics/${t.id}`); }
</script>

<AdminLayout title="Focus topics">
    <PageHeader title="Focus topics" description="Health focus areas shown during onboarding and tagging">
        {#snippet actions()}<Button onclick={create}><Plus class="size-4" /> New topic</Button>{/snippet}
    </PageHeader>
    <Card>
        <CardContent class="p-4">
            <Table>
                <TableHeader><TableRow><TableHead>Topic</TableHead><TableHead>Slug</TableHead><TableHead>Horses</TableHead><TableHead>Order</TableHead><TableHead></TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each topics as t (t.id)}
                        <TableRow>
                            <TableCell class="font-medium">{t.icon ?? ''} {t.title}</TableCell>
                            <TableCell class="font-mono text-xs text-muted-foreground">{t.slug}</TableCell>
                            <TableCell><Badge variant="muted">{t.horses_count}</Badge></TableCell>
                            <TableCell>{t.order}</TableCell>
                            <TableCell class="text-right">
                                <Button size="sm" variant="ghost" onclick={() => edit(t)}><Pencil class="size-4" /></Button>
                                <Button size="sm" variant="ghost" onclick={() => remove(t)}><Trash2 class="size-4 text-destructive" /></Button>
                            </TableCell>
                        </TableRow>
                    {/each}
                </TableBody>
            </Table>
        </CardContent>
    </Card>

    <Modal bind:open title={editing ? 'Edit topic' : 'New topic'}>
        <form id="ft-form" onsubmit={submit} class="space-y-4">
            <Field label="Title" error={$form.errors.title}><Input bind:value={$form.title} /></Field>
            <div class="grid grid-cols-2 gap-4">
                <Field label="Icon (emoji)" error={$form.errors.icon}><Input bind:value={$form.icon} /></Field>
                <Field label="Order" error={$form.errors.order}><Input type="number" bind:value={$form.order} /></Field>
            </div>
            <Field label="Slug" hint="Auto from title if blank" error={$form.errors.slug}><Input bind:value={$form.slug} /></Field>
            <Field label="Description" error={$form.errors.description}><Input bind:value={$form.description} /></Field>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Cancel</Button>
            <Button type="submit" form="ft-form" disabled={$form.processing}>{editing ? 'Save' : 'Create'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
