<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Badge, Separator } from '$lib/components/ui';
    import { formatDate } from '$lib/utils.js';
    import { statusVariant } from '$lib/badges.js';
    import { ArrowLeft, Archive, RotateCcw } from '@lucide/svelte';

    let { horse } = $props();

    function archive() {
        const note = prompt('Reason for archiving (optional):') ?? '';
        router.post(`/admin/horses/${horse.id}/archive`, { note });
    }
    function restore() {
        router.post(`/admin/horses/${horse.id}/restore`);
    }
</script>

<AdminLayout title="Horse detail">
    <Link href="/admin/horses" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Back to horses
    </Link>
    <PageHeader title={horse.name} description={`${horse.breed ?? 'Unknown breed'} · owned by ${horse.owner?.name ?? '—'}`}>
        {#snippet actions()}
            {#if horse.status === 'archived'}
                <Button onclick={restore}><RotateCcw class="size-4" /> Restore</Button>
            {:else}
                <Button variant="destructive" onclick={archive}><Archive class="size-4" /> Archive</Button>
            {/if}
        {/snippet}
    </PageHeader>

    <div class="grid gap-4 lg:grid-cols-3">
        <Card>
            <CardHeader><CardTitle>Demographics</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-muted-foreground">Sex</span><span>{horse.sex ?? '—'}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Age</span><span>{horse.age ?? '—'}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Weight</span><span>{horse.weight_kg ? `${horse.weight_kg} kg` : '—'}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Stable</span><span>{horse.stable ?? '—'}</span></div>
                <div class="flex justify-between items-center"><span class="text-muted-foreground">Status</span><Badge variant={statusVariant(horse.status)}>{horse.status}</Badge></div>
                {#if horse.focus_topics?.length}
                    <Separator class="my-2" />
                    <div class="flex flex-wrap gap-1">
                        {#each horse.focus_topics as f (f.id)}<Badge variant="secondary">{f.icon ?? ''} {f.title}</Badge>{/each}
                    </div>
                {/if}
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Shares & care team</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                {#each horse.shares as s (s.id)}
                    <div class="flex items-center justify-between border-b py-1 last:border-0">
                        <span>{s.therapist?.name ?? s.grantee_user?.name ?? 'Pending invite'}</span>
                        <Badge variant="muted">{s.role}</Badge>
                    </div>
                {:else}
                    <p class="text-muted-foreground">Not shared.</p>
                {/each}
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Active protocol</CardTitle></CardHeader>
            <CardContent class="text-sm">
                {#if horse.active_protocol}
                    <Link href={`/admin/protocols/${horse.active_protocol.id}`} class="font-medium hover:text-primary">{horse.active_protocol.title}</Link>
                    <div class="mt-1 text-muted-foreground">Week {horse.active_protocol.current_week ?? '?'} of {horse.active_protocol.total_weeks ?? '?'}</div>
                {:else}
                    <p class="text-muted-foreground">No active protocol.</p>
                {/if}
            </CardContent>
        </Card>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <Card>
            <CardHeader><CardTitle>Recent observations</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                {#each horse.observations as o (o.id)}
                    <div class="border-b py-1.5 last:border-0">
                        <div class="flex justify-between">
                            <span class="font-medium">{formatDate(o.date)}</span>
                            <span class="text-xs text-muted-foreground">{o.author?.name} {o.mood ? `· mood ${o.mood}/5` : ''}</span>
                        </div>
                        {#if o.note}<p class="text-muted-foreground">{o.note}</p>{/if}
                    </div>
                {:else}
                    <p class="text-muted-foreground">No observations.</p>
                {/each}
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Recent scans</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                {#each horse.scans as s (s.id)}
                    <div class="flex items-center justify-between border-b py-1.5 last:border-0">
                        <span><span class="font-medium">{s.product_name ?? 'Unknown'}</span> <span class="text-muted-foreground">· {s.brand}</span></span>
                        <Badge variant={statusVariant(s.rating)}>{s.rating} · {s.score}</Badge>
                    </div>
                {:else}
                    <p class="text-muted-foreground">No scans.</p>
                {/each}
            </CardContent>
        </Card>
    </div>
</AdminLayout>
