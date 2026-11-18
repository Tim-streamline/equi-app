<script>
    import ProtocolEvaluationReview from '$lib/components/ProtocolEvaluationReview.svelte';
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import HorseActions from '$lib/components/HorseActions.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import { Link } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Badge } from '$lib/components/ui';
    import { formatDate } from '$lib/utils.js';
    import { statusVariant } from '$lib/badges.js';
    import { ArrowLeft } from '@lucide/svelte';

    let { horse, evaluations = [] } = $props();

</script>

<AdminLayout title="Horse detail">
    <Link href="/admin/horses" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Back to horses
    </Link>
    <PageHeader title={horse.name} description={`${horse.breed ?? 'Unknown breed'} · owned by ${horse.owner?.name ?? '—'}`}>
        {#snippet actions()}
            <HorseActions {horse} />
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
    <section class="mt-6" aria-label="Evaluaties van dit paard">
        <h2 class="text-xl font-semibold">Evaluaties van {horse.name}</h2>
        {#each evaluations as evaluation (evaluation.protocol_id)}
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <Link href={`/admin/protocols/${evaluation.protocol_id}`} class="font-semibold text-primary">{evaluation.protocol_title}</Link>
                <a href={`/admin/horses/${horse.id}/evaluations/${evaluation.protocol_id}.pdf`} target="_blank" rel="noopener noreferrer" class="rounded-full border px-4 py-2 text-sm font-semibold">Exporteer evaluatie als PDF</a>
            </div>
            <ProtocolEvaluationReview {evaluation}/>
        {:else}
            <p class="mt-3 text-sm text-muted-foreground">Er zijn nog geen evaluaties verstuurd voor dit paard.</p>
        {/each}
    </section>
</AdminLayout>
