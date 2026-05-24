<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Field from '$lib/components/Field.svelte';
    import { Link, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Select } from '$lib/components/ui';
    import { ArrowLeft } from '@lucide/svelte';

    let { user } = $props();

    const form = useForm({
        name: user.name,
        email: user.email,
        locale: user.locale ?? 'nl-NL',
        units_system: user.units_system ?? 'metric',
        notifications_on: user.notifications_on,
        admin_note: user.admin_note ?? '',
    });

    function submit(e) {
        e.preventDefault();
        $form.put(`/admin/users/${user.id}`);
    }
</script>

<AdminLayout title="Edit user">
    <Link href={`/admin/users/${user.id}`} class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Back
    </Link>
    <PageHeader title={`Edit ${user.name}`} />

    <Card class="max-w-2xl">
        <CardContent class="p-6">
            <form onsubmit={submit} class="space-y-4">
                <Field label="Name" error={$form.errors.name}><Input bind:value={$form.name} /></Field>
                <Field label="Email" error={$form.errors.email}><Input type="email" bind:value={$form.email} /></Field>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field label="Locale" error={$form.errors.locale}><Input bind:value={$form.locale} /></Field>
                    <Field label="Units" error={$form.errors.units_system}>
                        <Select bind:value={$form.units_system} options={[{ value: 'metric', label: 'Metric' }, { value: 'imperial', label: 'Imperial' }]} />
                    </Field>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" bind:checked={$form.notifications_on} class="size-4 rounded border-input" />
                    Notifications enabled
                </label>
                <Field label="Internal note" error={$form.errors.admin_note} hint="Only visible to admins">
                    <Input bind:value={$form.admin_note} />
                </Field>
                <div class="flex gap-2 pt-2">
                    <Button type="submit" disabled={$form.processing}>Save changes</Button>
                    <Button variant="outline" href={`/admin/users/${user.id}`}>Cancel</Button>
                </div>
            </form>
        </CardContent>
    </Card>
</AdminLayout>
