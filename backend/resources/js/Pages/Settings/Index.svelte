<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { page, router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { Plus, Pencil, Trash2 } from '@lucide/svelte';

    let { admins, roles } = $props();
    const me = $derived($page.props.auth?.user);

    let open = $state(false);
    let editing = $state(null);
    const form = useForm({ name: '', email: '', role: 'support', active: true, password: '' });
    function create() { editing = null; form.defaults({ name: '', email: '', role: 'support', active: true, password: '' }); form.reset(); form.clearErrors(); open = true; }
    function edit(a) { editing = a; form.defaults({ name: a.name, email: a.email, role: a.role, active: a.active, password: '' }); form.reset(); open = true; }
    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: () => (open = false) };
        editing ? $form.put(`/admin/settings/admins/${editing.id}`, opts) : $form.post('/admin/settings/admins', opts);
    }
    function remove(a) { if (confirm(`Remove ${a.name}?`)) router.delete(`/admin/settings/admins/${a.id}`); }

    const roleLabel = (v) => roles.find((r) => r.value === v)?.label ?? v;
</script>

<AdminLayout title="Settings">
    <PageHeader title="Admin users & roles" description="Manage who has access to the back-office and at what scope">
        {#snippet actions()}<Button onclick={create}><Plus class="size-4" /> New admin</Button>{/snippet}
    </PageHeader>
    <Card>
        <CardContent class="p-4">
            <Table>
                <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Email</TableHead><TableHead>Role</TableHead><TableHead>Status</TableHead><TableHead></TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each admins as a (a.id)}
                        <TableRow>
                            <TableCell class="font-medium">{a.name}{#if a.id === me?.id}<Badge variant="secondary" class="ml-2">you</Badge>{/if}</TableCell>
                            <TableCell class="text-muted-foreground">{a.email}</TableCell>
                            <TableCell><Badge variant="muted">{roleLabel(a.role)}</Badge></TableCell>
                            <TableCell><Badge variant={a.active ? 'success' : 'destructive'}>{a.active ? 'active' : 'disabled'}</Badge></TableCell>
                            <TableCell class="text-right">
                                <Button size="sm" variant="ghost" onclick={() => edit(a)}><Pencil class="size-4" /></Button>
                                {#if a.id !== me?.id}<Button size="sm" variant="ghost" onclick={() => remove(a)}><Trash2 class="size-4 text-destructive" /></Button>{/if}
                            </TableCell>
                        </TableRow>
                    {/each}
                </TableBody>
            </Table>
        </CardContent>
    </Card>

    <Modal bind:open title={editing ? 'Edit admin user' : 'New admin user'}>
        <form id="admin-form" onsubmit={submit} class="space-y-4">
            <Field label="Name" error={$form.errors.name}><Input bind:value={$form.name} /></Field>
            <Field label="Email" error={$form.errors.email}><Input type="email" bind:value={$form.email} /></Field>
            <Field label="Role" error={$form.errors.role}>
                <Select bind:value={$form.role} options={roles.map((r) => ({ value: r.value, label: r.label }))} />
            </Field>
            <Field label={editing ? 'New password (blank = keep)' : 'Password'} error={$form.errors.password}>
                <Input type="password" bind:value={$form.password} />
            </Field>
            {#if editing}
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.active} class="size-4 rounded border-input" /> Active</label>
            {/if}
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Cancel</Button>
            <Button type="submit" form="admin-form" disabled={$form.processing}>{editing ? 'Save' : 'Create'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
