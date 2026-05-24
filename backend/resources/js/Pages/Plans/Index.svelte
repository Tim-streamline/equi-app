<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, CardHeader, CardTitle, Button, Input, Textarea, Select, Badge } from '$lib/components/ui';
    import { formatCents } from '$lib/utils.js';
    import { Plus, Pencil, Trash2, Check, X } from '@lucide/svelte';

    let { plans } = $props();

    let open = $state(false);
    let editing = $state(null);
    const form = useForm({
        name: '', label: '', slug: '', price_cents: 0, currency: 'EUR', interval: 'monthly',
        price_suffix: '', description: '', is_recommended: false, order: 0, benefits: [''],
    });

    function create() {
        editing = null;
        form.defaults({ name: '', label: '', slug: '', price_cents: 0, currency: 'EUR', interval: 'monthly', price_suffix: '', description: '', is_recommended: false, order: 0, benefits: [''] });
        form.reset(); form.clearErrors(); open = true;
    }
    function edit(p) {
        editing = p;
        form.defaults({ ...p, benefits: p.benefits?.map((b) => b.label) ?? [''] });
        form.reset(); open = true;
    }
    function addBenefit() { $form.benefits = [...$form.benefits, '']; }
    function removeBenefit(i) { $form.benefits = $form.benefits.filter((_, idx) => idx !== i); }
    function submit(e) {
        e.preventDefault();
        const opts = { onSuccess: () => (open = false) };
        editing ? $form.put(`/admin/plans/${editing.id}`, opts) : $form.post('/admin/plans', opts);
    }
    function remove(p) { if (confirm(`Delete ${p.name}?`)) router.delete(`/admin/plans/${p.id}`); }
</script>

<AdminLayout title="Plans">
    <PageHeader title="Plan catalog" description="Pricing, benefits and entitlements">
        {#snippet actions()}<Button onclick={create}><Plus class="size-4" /> New plan</Button>{/snippet}
    </PageHeader>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {#each plans as p (p.id)}
            <Card class={p.is_recommended ? 'border-primary' : ''}>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <CardTitle>{p.name}</CardTitle>
                        {#if p.is_recommended}<Badge>recommended</Badge>{/if}
                    </div>
                    <div class="text-2xl font-semibold">{formatCents(p.price_cents, p.currency)}<span class="text-sm font-normal text-muted-foreground">{p.price_suffix ? ` ${p.price_suffix}` : `/${p.interval}`}</span></div>
                </CardHeader>
                <CardContent>
                    {#if p.description}<p class="mb-3 text-sm text-muted-foreground">{p.description}</p>{/if}
                    <ul class="space-y-1 text-sm">
                        {#each p.benefits as b (b.id)}<li class="flex items-start gap-2"><Check class="mt-0.5 size-4 text-success" /> {b.label}</li>{/each}
                    </ul>
                    <div class="mt-4 flex items-center justify-between">
                        <Badge variant="muted">{p.subscriptions_count} active</Badge>
                        <div class="flex gap-1">
                            <Button size="sm" variant="ghost" onclick={() => edit(p)}><Pencil class="size-4" /></Button>
                            <Button size="sm" variant="ghost" onclick={() => remove(p)}><Trash2 class="size-4 text-destructive" /></Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        {/each}
    </div>

    <Modal bind:open size="lg" title={editing ? 'Edit plan' : 'New plan'}>
        <form id="plan-form" onsubmit={submit} class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <Field label="Name" error={$form.errors.name}><Input bind:value={$form.name} /></Field>
                <Field label="Label" error={$form.errors.label}><Input bind:value={$form.label} placeholder="optional badge" /></Field>
            </div>
            <div class="grid grid-cols-3 gap-4">
                <Field label="Price (cents)" error={$form.errors.price_cents}><Input type="number" bind:value={$form.price_cents} /></Field>
                <Field label="Currency"><Input bind:value={$form.currency} /></Field>
                <Field label="Interval" error={$form.errors.interval}>
                    <Select bind:value={$form.interval} options={[{ value: 'monthly', label: 'Monthly' }, { value: 'one_time', label: 'One-time' }]} />
                </Field>
            </div>
            <Field label="Description" error={$form.errors.description}><Textarea bind:value={$form.description} /></Field>
            <div>
                <div class="mb-1 text-sm font-medium">Benefits</div>
                <div class="space-y-2">
                    {#each $form.benefits as benefit, i (i)}
                        <div class="flex gap-2">
                            <Input bind:value={$form.benefits[i]} placeholder="Benefit line" />
                            <Button type="button" variant="ghost" size="icon" onclick={() => removeBenefit(i)}><X class="size-4" /></Button>
                        </div>
                    {/each}
                </div>
                <Button type="button" variant="outline" size="sm" class="mt-2" onclick={addBenefit}><Plus class="size-4" /> Add benefit</Button>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.is_recommended} class="size-4 rounded border-input" /> Recommended plan</label>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (open = false)}>Cancel</Button>
            <Button type="submit" form="plan-form" disabled={$form.processing}>{editing ? 'Save' : 'Create'}</Button>
        {/snippet}
    </Modal>
</AdminLayout>
