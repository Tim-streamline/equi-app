<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Badge } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { formatDateTime } from '$lib/utils.js';
    import { ArrowLeft, Flag, Trash2 } from '@lucide/svelte';

    let { scan } = $props();
    function toggleFlag() { router.post(`/admin/scans/${scan.id}/flag`); }
    function remove() { if (confirm('Delete this scan?')) router.delete(`/admin/scans/${scan.id}`); }
</script>

<AdminLayout title="Voerscanner · Scandetails">
    <Link href="/admin/scans" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Terug naar scanhistorie
    </Link>
    <PageHeader title={scan.product_name ?? 'Onbekend product'} description={`${scan.brand ?? ''} · gescand door ${scan.user?.name ?? 'onbekende gebruiker'}`}>
        {#snippet actions()}
            <Button variant="outline" onclick={toggleFlag}><Flag class="size-4" /> {scan.flagged ? 'Markering verwijderen' : 'Markeren voor controle'}</Button>
            <Button variant="ghost" onclick={remove}><Trash2 class="size-4 text-destructive" /></Button>
        {/snippet}
    </PageHeader>

    <div class="grid gap-4 lg:grid-cols-3">
        <Card>
            <CardHeader><CardTitle>Uitkomst</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                <div class="flex items-center justify-between"><span class="text-muted-foreground">Opgeslagen score</span><span class="text-lg font-semibold">{scan.score == null ? '—' : `${scan.score}/100`}</span></div>
                <p class="text-muted-foreground">Voorlopig of definitief: niet vastgelegd bij deze oudere scan.</p>
                <div class="flex items-center justify-between"><span class="text-muted-foreground">Beoordeling</span><Badge variant={statusVariant(scan.rating)}>{scan.rating ?? 'Niet vastgelegd'}</Badge></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Paard</span><span>{scan.horse?.name ?? '—'}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Wanneer</span><span>{formatDateTime(scan.scanned_at)}</span></div>
                {#if scan.advice}<p class="pt-2 text-muted-foreground">{scan.advice}</p>{/if}
            </CardContent>
        </Card>

        <Card class="lg:col-span-2">
            <CardHeader><CardTitle>Ingrediënten ({scan.ingredients?.length ?? 0})</CardTitle></CardHeader>
            <CardContent class="space-y-1.5 text-sm">
                {#each scan.ingredients ?? [] as ing (ing.id)}
                    <div class="flex items-center justify-between border-b py-1.5 last:border-0">
                        <span>{ing.name}</span>
                        <Badge variant={statusVariant(ing.tag)}>{ing.tag ?? '—'}</Badge>
                    </div>
                {:else}
                    <p class="text-muted-foreground">Geen ingrediënten opgeslagen.</p>
                {/each}
            </CardContent>
        </Card>
    </div>
</AdminLayout>
