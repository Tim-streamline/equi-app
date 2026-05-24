<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Textarea, Badge } from '$lib/components/ui';
    import { Plus, Pencil, Trash2, BadgeCheck } from '@lucide/svelte';

    let { therapists } = $props();

    let open = $state(false);
    let editing = $state(null);
    const form = useForm({ name: '', title: '', bio: '', avatar_url: '', avatar_initial: '', avatar_color: '', verified: false });

    function create() {
        editing = null;
        form.reset();
        form.clearErrors();
        open = true;
    }
    function edit(t) {
        editing = t;
        form.defaults({ ...t });
        form.reset();
        open = true;
    }
    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: () => (open = false) };
        if (editing) $form.put(`/admin/therapists/${editing.id}`, opts);
        else $form.post('/admin/therapists', opts);
    }
    function remove(t) {
        if (confirm(`Remove ${t.name}?`)) router.delete(`/admin/therapists/${t.id}`);
    }
</script>

<AdminLayout title="Therapists">
    <PageHeader title="Therapists & experts" description="Authors of protocols, content and expert replies">
        {#snippet actions()}<Button onclick={create}><Plus class="size-4" /> New therapist</Button>{/snippet}
    </PageHeader>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {#each therapists as t (t.id)}
            <Card>
                <CardContent class="p-5">
                    <div class="flex items-start gap-3">
                        <div class="flex size-11 items-center justify-center rounded-full text-sm font-semibold text-white" style={`background:${t.avatar_color || '#0d9488'}`}>
                            {t.avatar_initial || t.name.slice(0, 1)}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1 font-medium">
                                {t.name}
                                {#if t.verified}<BadgeCheck class="size-4 text-primary" />{/if}
                            </div>
                            <div class="text-sm text-muted-foreground">{t.title ?? ''}</div>
                        </div>
                    </div>
                    {#if t.bio}<p class="mt-3 line-clamp-2 text-sm text-muted-foreground">{t.bio}</p>{/if}
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <Badge variant="muted">{t.active_protocols} active protocols</Badge>
                        <Badge variant="muted">{t.library_items} articles</Badge>
                        <Badge variant="muted">{t.bookings_pending} bookings</Badge>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <Button size="sm" variant="outline" onclick={() => edit(t)}><Pencil class="size-4" /> Edit</Button>
                        <Button size="sm" variant="ghost" onclick={() => remove(t)}><Trash2 class="size-4 text-destructive" /></Button>
                    </div>
                </CardContent>
            </Card>
        {/each}
    </div>

    <Modal bind:open title={editing ? 'Edit therapist' : 'New therapist'}>
        <form id="t-form" onsubmit={submit} class="space-y-4">
            <Field label="Name" error={$form.errors.name}><Input bind:value={$form.name} /></Field>
            <Field label="Title" error={$form.errors.title}><Input bind:value={$form.title} placeholder="e.g. Equine therapist" /></Field>
            <Field label="Bio" error={$form.errors.bio}><Textarea bind:value={$form.bio} /></Field>
            <div class="grid grid-cols-2 gap-4">
                <Field label="Initials" error={$form.errors.avatar_initial}><Input maxlength="4" bind:value={$form.avatar_initial} /></Field>
                <Field label="Avatar color" error={$form.errors.avatar_color}><Input bind:value={$form.avatar_color} placeholder="#0d9488" /></Field>
            </div>
            <Field label="Avatar URL" error={$form.errors.avatar_url}><Input bind:value={$form.avatar_url} /></Field>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" bind:checked={$form.verified} class="size-4 rounded border-input" /> Verified expert
            </label>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Cancel</Button>
            <Button type="submit" form="t-form" disabled={$form.processing}>{editing ? 'Save' : 'Create'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
