<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Badge, Select, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { Plus, Search, Pencil, Trash2, AlertTriangle } from '@lucide/svelte';

    let { products, filters, reviewCount } = $props();
    let q = $state(filters.q ?? '');
    let review = $state(!!filters.review);
    let timer;
    function apply() {
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/products', { q, review: review ? 1 : undefined }, { preserveState: true, replace: true }), 250);
    }

    let open = $state(false);
    let editing = $state(null);
    const form = useForm({ brand: '', name: '', barcode: '', category: '', needs_review: false });
    function create() { editing = null; form.reset(); form.clearErrors(); open = true; }
    function edit(p) { editing = p; form.defaults({ ...p }); form.reset(); open = true; }
    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: () => (open = false) };
        editing ? $form.put(`/admin/products/${editing.id}`, opts) : $form.post('/admin/products', opts);
    }
    function remove(p) { if (confirm(`Delete ${p.brand} ${p.name}?`)) router.delete(`/admin/products/${p.id}`); }
</script>

<AdminLayout title="Products">
    <PageHeader title="Product catalog" description={`${products.total} products · ${reviewCount} need review`}>
        {#snippet actions()}<Button onclick={create}><Plus class="size-4" /> New product</Button>{/snippet}
    </PageHeader>

    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Search brand, name, barcode…" bind:value={q} oninput={apply} />
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={review} onchange={apply} class="size-4 rounded border-input" /> Needs review only</label>
            </div>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Brand / name</TableHead>
                        <TableHead>Barcode</TableHead>
                        <TableHead>Category</TableHead>
                        <TableHead>Scans</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {#each products.data as p (p.id)}
                        <TableRow>
                            <TableCell>
                                <div class="flex items-center gap-2 font-medium">
                                    {p.brand} {p.name}
                                    {#if p.needs_review}<AlertTriangle class="size-4 text-warning" />{/if}
                                </div>
                            </TableCell>
                            <TableCell class="font-mono text-xs text-muted-foreground">{p.barcode ?? '—'}</TableCell>
                            <TableCell>{p.category ? '' : ''}<Badge variant="muted">{p.category ?? 'uncategorised'}</Badge></TableCell>
                            <TableCell>{p.scans_count}</TableCell>
                            <TableCell class="text-right">
                                <Button size="sm" variant="ghost" onclick={() => edit(p)}><Pencil class="size-4" /></Button>
                                <Button size="sm" variant="ghost" onclick={() => remove(p)}><Trash2 class="size-4 text-destructive" /></Button>
                            </TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="5" class="py-8 text-center text-muted-foreground">No products.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={products} />
        </CardContent>
    </Card>

    <Modal bind:open title={editing ? 'Edit product' : 'New product'}>
        <form id="p-form" onsubmit={submit} class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <Field label="Brand" error={$form.errors.brand}><Input bind:value={$form.brand} /></Field>
                <Field label="Name" error={$form.errors.name}><Input bind:value={$form.name} /></Field>
            </div>
            <Field label="Barcode" error={$form.errors.barcode}><Input bind:value={$form.barcode} /></Field>
            <Field label="Category" error={$form.errors.category}><Input bind:value={$form.category} /></Field>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.needs_review} class="size-4 rounded border-input" /> Flag for expert review</label>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Cancel</Button>
            <Button type="submit" form="p-form" disabled={$form.processing}>{editing ? 'Save' : 'Create'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
