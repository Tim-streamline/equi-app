<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import { Link } from '@inertiajs/svelte';
    import { Card, CardContent, Badge } from '$lib/components/ui';
    let { statistics, tasks, pendingProducts, monthLabel } = $props();
    const states = { draft: 'Concept', legacy: 'Nog te controleren', needs_info: 'Informatie ontbreekt' };
</script>
<svelte:head><title>Voerscanner overzicht · EquiApp Admin</title></svelte:head>
<AdminLayout title="Voerscanner · Overzicht">
    <PageHeader title="Voerscanner" description="Jouw werkplek voor voerproducten en voedingskennis." />
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {#each [
            { label: 'Te beoordelen producten', count: statistics.review, href: '/admin/feed-scanner/products?status=review', note: 'Wachten op jouw controle' },
            { label: 'Bekende voerproducten', count: statistics.products, href: '/admin/feed-scanner/products', note: 'Inclusief bewaarde en gearchiveerde producten' },
            { label: 'Gepubliceerde kenniskaarten', count: statistics.published_cards, href: '/admin/feed-scanner/knowledge', note: 'Ingrediënten en voedingskundige kennis samen' },
            { label: 'Scans deze maand', count: statistics.month_scans, href: '/admin/scans', note: monthLabel },
        ] as stat}
            <Link href={stat.href} class="block rounded-xl border bg-card p-5 hover:border-primary"><p class="text-sm text-muted-foreground">{stat.label}</p><p class="my-2 text-3xl font-semibold">{stat.count}</p><p class="text-xs text-muted-foreground">{stat.note}</p></Link>
        {/each}
    </div>
    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <Card><CardContent class="space-y-4 p-5"><h2 class="text-lg font-semibold">Openstaande taken</h2>
            {#each tasks as task}<Link href={task.url} class="flex items-center justify-between gap-4 rounded-lg border p-4 text-sm hover:border-primary"><span>{task.title}</span><Badge variant="muted">{task.count}</Badge></Link>
            {:else}<p class="rounded-lg bg-muted p-4 text-sm text-muted-foreground">Er staan op dit moment geen taken open.</p>{/each}
        </CardContent></Card>
        <Card><CardContent class="space-y-4 p-5"><h2 class="text-lg font-semibold">Pak een product op</h2>
            {#each pendingProducts as product}<Link href={`/admin/feed-scanner/products?open=${product.id}`} class="block rounded-lg border p-4 hover:border-primary"><div class="text-xs text-muted-foreground">{product.brand}</div><div class="my-1 font-medium">{product.name}</div><Badge variant="muted">{states[product.status] ?? 'Te beoordelen'}</Badge></Link>
            {:else}<p class="rounded-lg bg-muted p-4 text-sm text-muted-foreground">Geen producten die op beoordeling wachten.</p>{/each}
            <Link href="/admin/feed-scanner/products" class="inline-block text-sm font-medium text-primary">Alle voerproducten bekijken →</Link>
        </CardContent></Card>
    </div>
</AdminLayout>
