<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Badge } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { ArrowLeft, Pencil } from '@lucide/svelte';

    let { protocol } = $props();
    function setStatus(status) { router.post(`/admin/protocols/${protocol.id}/status`, { status }); }
</script>

<AdminLayout title="Protocol">
    <Link href="/admin/protocols" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Back
    </Link>
    <PageHeader title={protocol.title} description={`${protocol.horse?.name} · ${protocol.therapist?.name ?? 'no therapist'}`}>
        {#snippet actions()}
            <Button href={`/admin/protocols/${protocol.id}/edit`}><Pencil class="size-4" /> Edit protocol</Button>
            <Button variant="outline" onclick={() => setStatus('active')}>Resume</Button>
            <Button variant="outline" onclick={() => setStatus('paused')}>Pause</Button>
            <Button variant="outline" onclick={() => setStatus('completed')}>Complete</Button>
        {/snippet}
    </PageHeader>

    <div class="mb-4 flex flex-wrap gap-3 text-sm">
        <Badge variant={statusVariant(protocol.status)}>{protocol.status}</Badge>
        <Badge variant="muted">Week {protocol.current_week ?? '?'} / {protocol.total_weeks ?? '?'}</Badge>
        <Badge variant="muted">{protocol.tasks?.length ?? 0} tasks</Badge>
    </div>

    {#if protocol.analysis}
        <Card class="mb-4">
            <CardHeader><CardTitle>Analysis</CardTitle></CardHeader>
            <CardContent class="text-sm text-muted-foreground whitespace-pre-wrap">{protocol.analysis.cause ?? '—'}</CardContent>
        </Card>
    {/if}

    <div class="grid gap-4 lg:grid-cols-2">
        <Card>
            <CardHeader><CardTitle>Phases</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                {#each protocol.phases as phase (phase.id)}
                    <div class="rounded-md border p-3">
                        <div class="flex items-center justify-between text-sm font-medium">
                            <span>{phase.title ?? phase.label ?? 'Phase'}</span>
                            {#if phase.state}<Badge variant="muted">{phase.state}</Badge>{/if}
                        </div>
                        {#if phase.items?.length}
                            <ul class="mt-1 list-disc pl-5 text-sm text-muted-foreground">
                                {#each phase.items as it (it.id)}<li>{it.label ?? it.title}</li>{/each}
                            </ul>
                        {/if}
                    </div>
                {:else}
                    <p class="text-sm text-muted-foreground">No phases.</p>
                {/each}
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Tasks & adherence</CardTitle></CardHeader>
            <CardContent class="space-y-1.5 text-sm">
                {#each protocol.tasks as task (task.id)}
                    <div class="flex items-center justify-between border-b py-1.5 last:border-0">
                        <span>{task.title ?? task.label}</span>
                        <Badge variant="muted">{task.completions_count} done</Badge>
                    </div>
                {:else}
                    <p class="text-muted-foreground">No tasks.</p>
                {/each}
            </CardContent>
        </Card>
    </div>
</AdminLayout>
