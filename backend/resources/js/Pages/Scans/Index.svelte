<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardContent, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { formatDate } from '$lib/utils.js';
    import { Search, Flag } from '@lucide/svelte';

    let { scans, filters, flaggedCount } = $props();
    let q = $state(filters.q ?? '');
    let rating = $state(filters.rating ?? '');
    let flagged = $state(!!Number(filters.flagged));
    let timer;
    function apply() {
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/scans', { q, rating, flagged: flagged ? 1 : undefined }, { preserveState: true, replace: true }), 250);
    }
</script>

<svelte:head><title>Scanhistorie · EquiApp Admin</title></svelte:head>
<AdminLayout title="Voerscanner · Scanhistorie">
    <PageHeader title="Scanhistorie" description={`${scans.total} scans · ${flaggedCount} gemarkeerd voor controle`} />
    <p class="mb-5 rounded-lg border bg-muted p-4 text-sm text-muted-foreground">Bij oudere scans is niet vastgelegd of de beoordeling voorlopig of definitief was. We leiden dat niet achteraf af uit de huidige productstatus. Hun opgeslagen scores hebben de oorspronkelijke schaal van 0–100.</p>
    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Zoek product of merk…" aria-label="Scans zoeken" bind:value={q} oninput={apply} />
                </div>
                <Select class="w-40" bind:value={rating} onchange={apply} options={[
                    { value: '', label: 'Alle uitkomsten' }, { value: 'Goed', label: 'Goed' }, { value: 'Matig', label: 'Matig' }, { value: 'Slecht', label: 'Slecht' }]} />
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={flagged} onchange={apply} class="size-4 rounded border-input" /> Alleen gemarkeerd</label>
            </div>
            <Table>
                <TableHeader><TableRow><TableHead>Product</TableHead><TableHead>Gebruiker</TableHead><TableHead>Score</TableHead><TableHead>Uitkomst</TableHead><TableHead>Beoordelingsstatus</TableHead><TableHead>Wanneer</TableHead><TableHead></TableHead></TableRow></TableHeader>
                <TableBody>
                    {#each scans.data as s (s.id)}
                        <TableRow class="cursor-pointer" onclick={() => router.visit(`/admin/scans/${s.id}`)}>
                            <TableCell><Link href={`/admin/scans/${s.id}`} onclick={e => e.stopPropagation()} class="font-medium hover:underline">{s.product_name ?? 'Onbekend product'}</Link><div class="text-xs text-muted-foreground">{s.brand}</div></TableCell>
                            <TableCell class="text-muted-foreground">{s.user?.name}</TableCell>
                            <TableCell>{s.score == null ? '—' : `${s.score}/100`}</TableCell>
                            <TableCell><Badge variant={statusVariant(s.rating)}>{s.rating ?? 'Niet vastgelegd'}</Badge></TableCell>
                            <TableCell><Badge variant="muted">Niet vastgelegd</Badge></TableCell>
                            <TableCell class="text-muted-foreground">{formatDate(s.scanned_at)}</TableCell>
                            <TableCell>{#if s.flagged}<Flag class="size-4 text-destructive" />{/if}</TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="7" class="py-8 text-center text-muted-foreground">Geen scans gevonden.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>
            <Pagination paginator={scans} />
        </CardContent>
    </Card>
</AdminLayout>
