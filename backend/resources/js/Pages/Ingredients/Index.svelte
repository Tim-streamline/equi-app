<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Textarea, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { Plus, Search, Pencil, Trash2 } from '@lucide/svelte';

    let { ingredients, filters } = $props();
    let q = $state(filters.q ?? '');
    let tag = $state(filters.tag ?? '');
    let timer;
    function apply() {
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/ingredients', { q, tag }, { preserveState: true, replace: true }), 250);
    }

    let open = $state(false);
    let editing = $state(null);
    const form = useForm({ name: '', description: '', default_tag: 'good', needs_review: false });
    function create() { editing = null; form.reset(); form.clearErrors(); open = true; }
    function edit(i) { editing = i; form.defaults({ ...i }); form.reset(); open = true; }
    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: () => (open = false) };
        editing ? $form.put(`/admin/ingredients/${editing.id}`, opts) : $form.post('/admin/ingredients', opts);
    }
    function remove(i) { if (confirm(`Delete ${i.name}?`)) router.delete(`/admin/ingredients/${i.id}`); }
</script>

<AdminLayout title="Ingredients">
    <PageHeader title="Ingredients" description={`${ingredients.total} ingredients with scoring tags`}>
        {#snippet actions()}<Button onclick={create}><Plus class="size-4" /> New ingredient</Button>{/snippet}
    </PageHeader>

    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Search ingredient…" bind:value={q} oninput={apply} />
                </div>
                <Select class="w-40" bind:value={tag} onchange={apply} options={[
                    { value: '', label: 'All tags' }, { value: 'good', label: 'Good' },
                    { value: 'warn', label: 'Warn' }, { value: 'danger', label: 'Danger' }]} />
            </div>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Tag</TableHead>
                        <TableHead>Explanation</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {#each ingredients.data as i (i.id)}
                        <TableRow>
                            <TableCell class="font-medium">{i.name}</TableCell>
                            <TableCell>{#if i.default_tag}<Badge variant={statusVariant(i.default_tag)}>{i.default_tag}</Badge>{:else}—{/if}</TableCell>
                            <TableCell class="max-w-md truncate text-muted-foreground">{i.description ?? '—'}</TableCell>
                            <TableCell class="text-right">
                                <Button size="sm" variant="ghost" onclick={() => edit(i)}><Pencil class="size-4" /></Button>
                                <Button size="sm" variant="ghost" onclick={() => remove(i)}><Trash2 class="size-4 text-destructive" /></Button>
                            </TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="4" class="py-8 text-center text-muted-foreground">No ingredients.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={ingredients} />
        </CardContent>
    </Card>

    <Modal bind:open title={editing ? 'Edit ingredient' : 'New ingredient'}>
        <form id="i-form" onsubmit={submit} class="space-y-4">
            <Field label="Name" error={$form.errors.name}><Input bind:value={$form.name} /></Field>
            <Field label="Default tag" error={$form.errors.default_tag}>
                <Select bind:value={$form.default_tag} options={[
                    { value: 'good', label: 'Good' }, { value: 'warn', label: 'Warn' }, { value: 'danger', label: 'Danger' }]} />
            </Field>
            <Field label="Explanation" error={$form.errors.description}><Textarea bind:value={$form.description} /></Field>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.needs_review} class="size-4 rounded border-input" /> Needs expert review</label>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Cancel</Button>
            <Button type="submit" form="i-form" disabled={$form.processing}>{editing ? 'Save' : 'Create'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
