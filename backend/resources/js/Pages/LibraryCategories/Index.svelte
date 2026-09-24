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
    const form = useForm({ label: '', slug: '', order: 0, is_default: false, is_quick_filter: false });
    function emptyCategory() {
        const lastOrder = Math.max(-1, ...categories.map((category) => Number(category.order) || 0));
        return { label: '', slug: '', order: lastOrder + 1, is_default: false, is_quick_filter: false };
    }
    function create() {
        editing = null;
        $form.defaults(emptyCategory());
        $form.reset();
        $form.clearErrors();
        open = true;
    }
    function edit(c) { editing = c; $form.defaults({ ...c }); $form.reset(); open = true; }
    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: () => (open = false) };
        editing ? $form.put(`/admin/library-categories/${editing.id}`, opts) : $form.post('/admin/library-categories', opts);
    }
    function remove(c) { if (confirm(`${c.label} verwijderen?`)) router.delete(`/admin/library-categories/${c.id}`); }
</script>

<AdminLayout title="Library-categorieën">
    <PageHeader title="Library-categorieën" description="Beheer de filters voor de contentbibliotheek">
        {#snippet actions()}
            <Button aria-label="Nieuwe bibliotheekcategorie toevoegen" onclick={create} class="rounded-full bg-[#18BAB0] px-5 hover:bg-[#108A82]">
                <Plus class="size-4" /> Nieuwe categorie
            </Button>
        {/snippet}
    </PageHeader>
    <Card>
        <CardContent class="p-4">
            <Table>
                <TableHeader><TableRow><TableHead>Naam</TableHead><TableHead>Slug</TableHead><TableHead>Items</TableHead><TableHead>Standaard</TableHead><TableHead>Snelle filter</TableHead><TableHead></TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each categories as c (c.id)}
                        <TableRow>
                            <TableCell class="font-medium">{c.label}</TableCell>
                            <TableCell class="font-mono text-xs text-muted-foreground">{c.slug}</TableCell>
                            <TableCell><Badge variant="muted">{c.items_count}</Badge></TableCell>
                            <TableCell>{#if c.is_default}<Badge>standaard</Badge>{/if}</TableCell>
                            <TableCell>{#if c.is_quick_filter}<Badge>snelle filter · {c.order}</Badge>{/if}</TableCell>
                            <TableCell class="text-right">
                                <Button aria-label={`${c.label} aanpassen`} size="sm" variant="ghost" onclick={() => edit(c)}><Pencil class="size-4" /></Button>
                                <Button aria-label={`${c.label} verwijderen`} size="sm" variant="ghost" onclick={() => remove(c)}><Trash2 class="size-4 text-destructive" /></Button>
                            </TableCell>
                        </TableRow>
                    {/each}
                </TableBody>
            </Table>
        </CardContent>
    </Card>

    <Modal bind:open title={editing ? 'Categorie aanpassen' : 'Nieuwe categorie'}>
        <form id="lc-form" onsubmit={submit} class="space-y-4">
            <Field label="Naam" error={$form.errors.label}><Input bind:value={$form.label} /></Field>
            <Field label="Slug" hint="Wordt automatisch aangemaakt wanneer dit veld leeg blijft" error={$form.errors.slug}><Input bind:value={$form.slug} /></Field>
            <Field label="Volgorde" error={$form.errors.order}><Input type="number" min="0" bind:value={$form.order} /></Field>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.is_quick_filter} class="size-4 rounded border-input" /> Toon als snelle filter in Library</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.is_default} class="size-4 rounded border-input" /> Standaardcategorie</label>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Annuleren</Button>
            <Button type="submit" form="lc-form" disabled={$form.processing}>{editing ? 'Opslaan' : 'Categorie toevoegen'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
