<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Field from '$lib/components/Field.svelte';
    import { useForm, router } from '@inertiajs/svelte';
    import { Button, Input } from '$lib/components/ui';
    let { settings, bundles, account, balance, orders } = $props();
    const config = useForm({ monthly_credits: settings.monthly_credits, membership_cap: settings.membership_cap, purchase_validity_months: settings.purchase_validity_months });
    const form = useForm({ label: '', credits: 1, price_cents: 100, currency: 'EUR', active: false, order: 0 });
    let editing = $state(null);
    let userId = $state(account?.id ?? '');
    function edit(bundle) { editing = bundle.id; form.defaults(bundle); form.reset(); }
    function save(event) { event.preventDefault(); const opts = { onSuccess: () => { editing = null; form.defaults({ label: '', credits: 1, price_cents: 100, currency: 'EUR', active: false, order: 0 }); form.reset(); } }; editing ? $form.put(`/admin/credits/bundles/${editing}`, opts) : $form.post('/admin/credits/bundles', opts); }
</script>
<AdminLayout title="Credits">
    <PageHeader title="Credits" description="Instellingen, bundels en betaaltransacties. Basic-prijzen beheer je bij Plans." />
    <form onsubmit={(e) => { e.preventDefault(); $config.put('/admin/credits/settings'); }} class="mb-8 grid max-w-2xl gap-4 sm:grid-cols-3">
        <Field label="Credits per betaalde verlenging" error={$config.errors.monthly_credits}><Input type="number" min="0" bind:value={$config.monthly_credits} /></Field>
        <Field label="Maximum membership-credits" error={$config.errors.membership_cap}><Input type="number" min="0" bind:value={$config.membership_cap} /></Field>
        <Field label="Geldigheid aankopen (maanden)" error={$config.errors.purchase_validity_months}><Input type="number" min="1" bind:value={$config.purchase_validity_months} /></Field>
        <Button type="submit" disabled={$config.processing}>Instellingen opslaan</Button>
    </form>
    <h2 class="mb-3 text-xl font-semibold">Creditbundels</h2>
    <div class="mb-5 space-y-2">
        {#each bundles as bundle}<div class="flex flex-wrap items-center gap-4 rounded border p-3"><span>{bundle.label || 'Bundel'} · {bundle.credits} credits · € {(bundle.price_cents / 100).toFixed(2)} · {bundle.active ? 'Actief' : 'Inactief'} · Volgorde {bundle.order}</span><Button variant="outline" onclick={() => edit(bundle)}>Bewerken</Button></div>{/each}
        {#if !bundles.length}<p>Er zijn nog geen bundels. Voeg hieronder een bundel met de gewenste prijs toe.</p>{/if}
    </div>
    <form onsubmit={save} class="mb-8 grid max-w-2xl gap-4 sm:grid-cols-2">
        <Field label="Label (optioneel)" error={$form.errors.label}><Input bind:value={$form.label} /></Field>
        <Field label="Aantal credits" error={$form.errors.credits}><Input type="number" min="1" bind:value={$form.credits} /></Field>
        <Field label="Prijs in eurocenten" error={$form.errors.price_cents}><Input type="number" min="1" bind:value={$form.price_cents} /></Field>
        <Field label="Volgorde" error={$form.errors.order}><Input type="number" min="0" bind:value={$form.order} /></Field>
        <label><input type="checkbox" bind:checked={$form.active} /> Actief</label>
        <Button type="submit" disabled={$form.processing}>{editing ? 'Bundel opslaan' : 'Bundel toevoegen'}</Button>
    </form>
    <h2 class="mb-3 text-xl font-semibold">Support: credits en betalingen</h2>
    <form onsubmit={(e) => { e.preventDefault(); router.get('/admin/credits', { user: userId }); }} class="mb-4 flex max-w-xl gap-3"><Input aria-label="User ID" placeholder="User ID" bind:value={userId} /><Button type="submit">Account bekijken</Button></form>
    {#if account}<p class="mb-4">{account.name} · {account.email} · {balance.balance} credits beschikbaar</p>{/if}
    <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th>Datum</th><th>Account</th><th>Betaling</th><th>Status</th><th>Credits</th><th>Besteed vóór terugboeking</th></tr></thead><tbody>
        {#each orders.data as order}<tr class="border-b"><td class="py-3">{order.created_at}</td><td><a class="underline" href={`/admin/credits?user=${order.user_id}`}>{order.user_id}</a></td><td>{order.payment_id || '—'}</td><td>{order.status}</td><td>{order.credits}</td><td>{order.spent_before_reversal}</td></tr>{/each}
    </tbody></table></div>
    <div class="my-4 flex gap-3">{#if orders.prev_page_url}<Button href={orders.prev_page_url}>Vorige</Button>{/if}{#if orders.next_page_url}<Button href={orders.next_page_url}>Volgende</Button>{/if}</div>
    {#if balance}<h3 class="my-4 font-semibold">Creditgeschiedenis</h3><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th>Datum</th><th>Omschrijving</th><th>Mutatie</th><th>Type / bron</th><th>Betaling / item</th><th>Reden</th></tr></thead><tbody>
    {#each balance.history as row}<tr class="border-b"><td class="py-2">{row.created_at}</td><td>{row.description}</td><td>{row.amount > 0 ? '+' : ''}{row.amount}</td><td>{row.type} / {row.source}</td><td>{row.payment_id || row.item_id || '—'}</td><td>{row.reason || '—'}</td></tr>{/each}</tbody></table></div>{/if}
</AdminLayout>
