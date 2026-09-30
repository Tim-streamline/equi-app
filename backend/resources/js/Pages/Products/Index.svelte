<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Badge, Select, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { Plus, Search, Pencil, Trash2, AlertTriangle, ArrowUp, ArrowDown } from '@lucide/svelte';

    let { products, filters, reviewCount, ingredientOptions } = $props();
    let q = $state(filters.q ?? '');
    let review = $state(!!filters.review);
    let timer;
    function apply() {
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/products', { q, review: review ? 1 : undefined }, { preserveState: true, replace: true }), 250);
    }

    let open = $state(false);
    let editing = $state(null);
    const emptyProduct = () => ({ brand: '', name: '', barcode: '', category: '', needs_review: false, ingredients: [] });
    const form = useForm(emptyProduct());
    function create() { editing = null; $form.defaults(emptyProduct()); $form.reset(); $form.clearErrors(); open = true; }
    function edit(p) {
        editing = p;
        $form.defaults({
            brand: p.brand, name: p.name, barcode: p.barcode ?? '', category: p.category ?? '', needs_review: p.needs_review,
            ingredients: p.ingredients.map((i, index) => ({ ingredient_id: i.id, order: index + 1, amount: i.pivot.amount ?? '' })),
        });
        $form.reset(); $form.clearErrors(); open = true;
    }
    function addIngredient() {
        $form.ingredients = [...$form.ingredients, { ingredient_id: '', order: $form.ingredients.length + 1, amount: '' }];
    }
    function reorderIngredients(rows) {
        $form.ingredients = rows.map((row, index) => ({ ...row, order: index + 1 }));
        $form.clearErrors();
    }
    function moveIngredient(index, direction) {
        const rows = [...$form.ingredients];
        [rows[index], rows[index + direction]] = [rows[index + direction], rows[index]];
        reorderIngredients(rows);
    }
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
                        <TableHead>Ingredients</TableHead>
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
                            <TableCell>{p.ingredients.length}</TableCell>
                            <TableCell>{p.scans_count}</TableCell>
                            <TableCell class="text-right">
                                <Button size="sm" variant="ghost" aria-label={`Edit ${p.brand} ${p.name}`} onclick={() => edit(p)}><Pencil class="size-4" /></Button>
                                <Button size="sm" variant="ghost" onclick={() => remove(p)}><Trash2 class="size-4 text-destructive" /></Button>
                            </TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="6" class="py-8 text-center text-muted-foreground">No products.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={products} />
        </CardContent>
    </Card>

    <Modal bind:open title={editing ? 'Edit product' : 'New product'} size="lg">
        <form id="p-form" onsubmit={submit} class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <Field label="Brand" error={$form.errors.brand}><Input bind:value={$form.brand} /></Field>
                <Field label="Name" error={$form.errors.name}><Input bind:value={$form.name} /></Field>
            </div>
            <Field label="Barcode" error={$form.errors.barcode}><Input bind:value={$form.barcode} /></Field>
            <Field label="Category" error={$form.errors.category}><Input bind:value={$form.category} /></Field>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.needs_review} class="size-4 rounded border-input" /> Flag for expert review</label>
            <section class="space-y-3 border-t pt-4" aria-label="Product ingredients">
                <div class="flex items-center justify-between gap-2">
                    <h4 class="font-medium">Ingredients</h4>
                    <Button type="button" size="sm" variant="outline" onclick={addIngredient} disabled={$form.ingredients.length >= ingredientOptions.length}><Plus class="size-4" /> Add ingredient</Button>
                </div>
                <p class="text-sm text-muted-foreground">Use the arrows to set the order. Amount can include a unit, e.g. 250 mg or 12%.</p>
                {#if $form.errors.ingredients}<p class="text-sm text-destructive">{$form.errors.ingredients}</p>{/if}
                {#each $form.ingredients as row, index}
                    <div class="space-y-2 rounded-lg border p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium">Order {index + 1}</span>
                            <div class="flex gap-1">
                                <Button type="button" size="sm" variant="ghost" aria-label={`Move ingredient ${index + 1} up`} disabled={index === 0} onclick={() => moveIngredient(index, -1)}><ArrowUp class="size-4" /></Button>
                                <Button type="button" size="sm" variant="ghost" aria-label={`Move ingredient ${index + 1} down`} disabled={index === $form.ingredients.length - 1} onclick={() => moveIngredient(index, 1)}><ArrowDown class="size-4" /></Button>
                                <Button type="button" size="sm" variant="ghost" aria-label={`Remove ingredient ${index + 1}`} onclick={() => reorderIngredients($form.ingredients.filter((_, i) => i !== index))}><Trash2 class="size-4 text-destructive" /></Button>
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Field label="Ingredient" error={$form.errors[`ingredients.${index}.ingredient_id`]}>
                                <Select aria-label={`Ingredient ${index + 1}`} bind:value={row.ingredient_id} options={[
                                    { value: '', label: 'Select ingredient…' },
                                    ...ingredientOptions.filter(i => i.id === row.ingredient_id || !$form.ingredients.some(r => r.ingredient_id === i.id)).map(i => ({ value: i.id, label: i.name })),
                                ]} />
                            </Field>
                            <Field label="Amount (optional)" error={$form.errors[`ingredients.${index}.amount`]}><Input aria-label={`Amount for ingredient ${index + 1}`} bind:value={row.amount} maxlength={255} placeholder="e.g. 250 mg" /></Field>
                        </div>
                        {#if $form.errors[`ingredients.${index}.order`]}<p class="text-sm text-destructive">{$form.errors[`ingredients.${index}.order`]}</p>{/if}
                    </div>
                {:else}
                    <p class="text-sm text-muted-foreground">No ingredients linked to this product.</p>
                {/each}
                {#if ingredientOptions.length === 0}<p class="text-sm text-muted-foreground">Create ingredients in the <a class="underline" href="/admin/ingredients">ingredient catalog</a> first.</p>{/if}
            </section>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Cancel</Button>
            <Button type="submit" form="p-form" disabled={$form.processing}>{editing ? 'Save' : 'Create'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
