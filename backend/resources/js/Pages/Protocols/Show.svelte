<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Badge } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { ArrowLeft, Check, Dumbbell, Leaf, Pencil, Settings2 } from '@lucide/svelte';

    let { protocol } = $props();
    const adviceCategories = $derived([
        {
            id: 'voeding',
            title: 'Voeding',
            description: 'Voedingskeuzes binnen dit protocol',
            icon: Leaf,
            items: protocol.voeding_adviezen ?? [],
        },
        {
            id: 'management',
            title: 'Management',
            description: 'Dagelijkse verzorging en omgeving',
            icon: Settings2,
            items: protocol.management_adviezen ?? [],
        },
        {
            id: 'beweging',
            title: 'Beweging',
            description: 'Opbouw van activiteit en herstel',
            icon: Dumbbell,
            items: protocol.beweging_adviezen ?? [],
        },
    ]);
    const selectedAdviceCount = $derived(adviceCategories.reduce((total, category) => total + category.items.length, 0));
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
    </div>

    {#if protocol.analysis}
        <Card class="mb-4">
            <CardHeader><CardTitle>Analysis</CardTitle></CardHeader>
            <CardContent class="text-sm text-muted-foreground whitespace-pre-wrap">{protocol.analysis.cause ?? '—'}</CardContent>
        </Card>
    {/if}

    <section class="mb-6 border-y bg-muted/15" aria-labelledby="selected-advice-title">
        <div class="flex flex-wrap items-end justify-between gap-3 px-1 py-5">
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-[0.16em] text-primary">Protocolinhoud</p>
                <h2 id="selected-advice-title" class="text-xl font-semibold tracking-tight">Geselecteerde adviezen</h2>
                <p class="mt-1 text-sm text-muted-foreground">De vastgelegde adviezen voor voeding, management en beweging.</p>
            </div>
            <Badge variant={selectedAdviceCount > 0 ? 'default' : 'muted'}>
                {selectedAdviceCount} {selectedAdviceCount === 1 ? 'advies' : 'adviezen'}
            </Badge>
        </div>

        <div class="divide-y border-t lg:grid lg:grid-cols-3 lg:divide-x lg:divide-y-0">
            {#each adviceCategories as category (category.id)}
                {@const Icon = category.icon}
                <article class="px-1 py-5 lg:px-5 first:lg:pl-1 last:lg:pr-1">
                    <div class="mb-4 flex items-start gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Icon class="size-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold">{category.title}</h3>
                                <span class="text-xs tabular-nums text-muted-foreground">{category.items.length}</span>
                            </div>
                            <p class="text-xs text-muted-foreground">{category.description}</p>
                        </div>
                    </div>

                    {#if category.items.length > 0}
                        <ul class="space-y-4">
                            {#each category.items as advice (advice.id)}
                                <li class="grid grid-cols-[1rem_1fr] gap-2.5">
                                    <Check class="mt-0.5 size-4 text-primary" aria-hidden="true" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium leading-5">{advice.title}</p>
                                        {#if advice.description}
                                            <p class="mt-1 whitespace-pre-wrap text-sm leading-5 text-muted-foreground">{advice.description}</p>
                                        {/if}
                                    </div>
                                </li>
                            {/each}
                        </ul>
                    {:else}
                        <p class="text-sm text-muted-foreground">Geen advies geselecteerd.</p>
                    {/if}
                </article>
            {/each}
        </div>
    </section>

    <div>
        <Card>
            <CardHeader><CardTitle>Phases</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                {#each protocol.phases as phase (phase.id)}
                    <div class="rounded-md border p-3">
                        <div class="flex items-center justify-between text-sm font-medium">
                            <span>{phase.title ?? phase.label ?? 'Phase'}</span>
                            {#if phase.state}<Badge variant="muted">{phase.state}</Badge>{/if}
                        </div>
                    </div>
                {:else}
                    <p class="text-sm text-muted-foreground">No phases.</p>
                {/each}
            </CardContent>
        </Card>
    </div>
</AdminLayout>
