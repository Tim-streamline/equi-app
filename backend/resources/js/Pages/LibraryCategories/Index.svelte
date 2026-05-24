<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { Plus, Pencil, Trash2 } from '@lucide/svelte';

    let { categories } = $props();
    let open = $state(false);
    let editing = $state(null);
    const form = useForm({ label: '', slug: '', order: 0, is_default: false });
    function create() { editing = null; form.reset(); form.clearErrors(); open = true; }
    function edit(c) { editing = c; form.defaults({ ...c }); form.reset(); open = true; }
    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: () => (open = false) };
        editing ? $form.put(`/admin/library-categories/${editing.id}`, opts) : $form.post('/admin/library-categories', opts);
    }
    function remove(c) { if (confirm(`Delete ${c.label}?`)) router.delete(`/admin/library-categories/${c.id}`); }
</script>

<AdminLayout title="Library categories">
    <PageHeader title="Library categories" description="Browse filters for the content library">
        {#snippet actions()}<Button onclick={create}><Plus class="size-4" /> New category</Button>{/snippet}
    </PageHeader>
    <Card>
        <CardContent class="p-4">
            <Table>
                <TableHeader><TableRow><TableHead>Label</TableHead><TableHead>Slug</TableHead><TableHead>Items</TableHead><TableHead>Default</TableHead><TableHead></TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each categories as c (c.id)}
                        <TableRow>
                            <TableCell class="font-medium">{c.label}</TableCell>
                            <TableCell class="font-mono text-xs text-muted-foreground">{c.slug}</TableCell>
                            <TableCell><Badge variant="muted">{c.items_count}</Badge></TableCell>
                            <TableCell>{#if c.is_default}<Badge>default</Badge>{/if}</TableCell>
                            <TableCell class="text-right">
                                <Button size="sm" variant="ghost" onclick={() => edit(c)}><Pencil class="size-4" /></Button>
                                <Button size="sm" variant="ghost" onclick={() => remove(c)}><Trash2 class="size-4 text-destructive" /></Button>
                            </TableCell>
                        </TableRow>
                    {/each}
                </TableBody>
            </Table>
        </CardContent>
    </Card>

    <Modal bind:open title={editing ? 'Edit category' : 'New category'}>
        <form id="lc-form" onsubmit={submit} class="space-y-4">
            <Field label="Label" error={$form.errors.label}><Input bind:value={$form.label} /></Field>
            <Field label="Slug" hint="Auto if blank" error={$form.errors.slug}><Input bind:value={$form.slug} /></Field>
            <Field label="Order" error={$form.errors.order}><Input type="number" bind:value={$form.order} /></Field>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.is_default} class="size-4 rounded border-input" /> Default category</label>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Cancel</Button>
            <Button type="submit" form="lc-form" disabled={$form.processing}>{editing ? 'Save' : 'Create'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
