<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { Plus, Pencil, Trash2 } from '@lucide/svelte';

    let { categories, groups } = $props();
    let selectedGroup = $state(groups[0]?.id ?? '');
    let groupOpen = $state(false);
    let editingGroup = $state(null);
    const groupForm = useForm({ label: '', order: 0, active: true });
    function editGroup(group = null) {
        editingGroup = group;
        $groupForm.defaults({ label: group?.label ?? '', order: group?.order ?? groups.length, active: group?.active ?? true });
        $groupForm.reset(); $groupForm.clearErrors(); groupOpen = true;
    }
    function submitGroup(event) {
        event.preventDefault();
        const options = { onSuccess: () => { groupOpen = false; } };
        editingGroup ? $groupForm.put(`/admin/library-filter-groups/${editingGroup.id}`, options) : $groupForm.post('/admin/library-filter-groups', options);
    }
    let open = $state(false);
    let editing = $state(null);
    const form = useForm({ label: '', slug: '', order: 0, is_default: false, is_quick_filter: false, group_id: selectedGroup, active: true });
    function emptyCategory() {
        const lastOrder = Math.max(-1, ...categories.map((category) => Number(category.order) || 0));
        return { label: '', slug: '', order: lastOrder + 1, is_default: false, is_quick_filter: false, group_id: selectedGroup, active: true };
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
    function remove(c) { if (confirm(`${c.label} archiveren? Bestaande koppelingen blijven bewaard.`)) router.delete(`/admin/library-categories/${c.id}`); }
</script>

<AdminLayout title="Bibliotheekfilters">
    <PageHeader title="Bibliotheekfilters" description="Beheer filtergroepen en subcategorieën. Persoonlijk en Soort worden automatisch bepaald.">
        {#snippet actions()}
            <Button variant="outline" onclick={() => editGroup()}><Plus class="size-4" /> Nieuwe filtergroep</Button>
            <Button aria-label="Nieuwe bibliotheekcategorie toevoegen" onclick={create} class="rounded-full bg-[#18BAB0] px-5 hover:bg-[#108A82]">
                <Plus class="size-4" /> Nieuwe categorie
            </Button>
        {/snippet}
    </PageHeader>
    <Card>
        <CardContent class="p-4">
            <div class="mb-5 flex flex-wrap items-center gap-3">
                <label for="filter-group">Filtergroep</label>
                <select id="filter-group" class="rounded border p-2" bind:value={selectedGroup}>
                    {#each groups as group}<option value={group.id}>{group.label}{!group.active ? ' (gearchiveerd)' : ''}</option>{/each}
                </select>
                <Button variant="outline" onclick={() => editGroup(groups.find(g => g.id === selectedGroup))}>Groep aanpassen</Button>
            </div>
            <Table>
                <TableHeader><TableRow><TableHead>Naam</TableHead><TableHead>Slug</TableHead><TableHead>Items</TableHead><TableHead>Standaard</TableHead><TableHead>Snelle filter</TableHead><TableHead></TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each categories.filter(c => c.group_id === selectedGroup) as c (c.id)}
                        <TableRow>
                            <TableCell class="font-medium">{c.label}{#if !c.active}<Badge variant="muted">gearchiveerd</Badge>{/if}</TableCell>
                            <TableCell class="font-mono text-xs text-muted-foreground">{c.slug}</TableCell>
                            <TableCell><Badge variant="muted">{c.items_count}</Badge></TableCell>
                            <TableCell>{#if c.is_default}<Badge>standaard</Badge>{/if}</TableCell>
                            <TableCell>{#if c.is_quick_filter}<Badge>snelle filter · {c.order}</Badge>{/if}</TableCell>
                            <TableCell class="text-right">
                                <Button aria-label={`${c.label} aanpassen`} size="sm" variant="ghost" onclick={() => edit(c)}><Pencil class="size-4" /></Button>
                                <Button aria-label={`${c.label} archiveren`} size="sm" variant="ghost" onclick={() => remove(c)}><Trash2 class="size-4 text-destructive" /></Button>
                            </TableCell>
                        </TableRow>
                    {/each}
                </TableBody>
            </Table>
        </CardContent>
    </Card>

    <Modal bind:open title={editing ? 'Categorie aanpassen' : 'Nieuwe categorie'}>
        <form id="lc-form" onsubmit={submit} class="space-y-4">
            <Field label="Filtergroep" error={$form.errors.group_id}>
                <select class="w-full rounded border p-2" bind:value={$form.group_id}>{#each groups as group}<option value={group.id}>{group.label}{!group.active ? ' (gearchiveerd)' : ''}</option>{/each}</select>
            </Field>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.active} /> Actieve subcategorie</label>
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
    <Modal bind:open={groupOpen} title={editingGroup ? 'Filtergroep aanpassen' : 'Nieuwe filtergroep'}>
        <form id="group-form" onsubmit={submitGroup} class="space-y-4">
            <Field label="Naam" error={$groupForm.errors.label}><Input bind:value={$groupForm.label} /></Field>
            <Field label="Volgorde" error={$groupForm.errors.order}><Input type="number" min="0" bind:value={$groupForm.order} /></Field>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$groupForm.active} /> Actieve filtergroep</label>
            <p class="text-sm text-muted-foreground">Een inactieve groep wordt verborgen in de app. Subcategorieën en koppelingen blijven bewaard.</p>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (groupOpen = false)}>Annuleren</Button>
            <Button type="submit" form="group-form" disabled={$groupForm.processing}>Opslaan</Button>
        {/snippet}
    </Modal>
</AdminLayout>
