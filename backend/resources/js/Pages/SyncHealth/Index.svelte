<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import { Card, CardHeader, CardTitle, CardContent, Badge } from '$lib/components/ui';
    import { AlertTriangle, CheckCircle2, Database } from '@lucide/svelte';

    let { config, db } = $props();
</script>

<AdminLayout title="Sync health">
    <PageHeader title="PowerSync health" description="Stream configuration and sensitive-table exposure" />

    {#if config.exposed_sensitive?.length}
        <div class="mb-4 flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
            <AlertTriangle class="mt-0.5 size-5 shrink-0" />
            <div>
                <div class="font-medium">{config.exposed_sensitive.length} sensitive table(s) are in the sync stream</div>
                <div>These should be server-only before production: {config.exposed_sensitive.join(', ')}</div>
            </div>
        </div>
    {:else}
        <div class="mb-4 flex items-center gap-3 rounded-lg border border-success/30 bg-success/10 p-4 text-sm text-success">
            <CheckCircle2 class="size-5" /> No known sensitive tables detected in the sync rules.
        </div>
    {/if}

    <div class="grid gap-4 lg:grid-cols-2">
        <Card>
            <CardHeader><CardTitle>Configuration</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-muted-foreground">Sync rules file</span><Badge variant={config.rules_present ? 'success' : 'destructive'}>{config.rules_present ? 'present' : 'missing'}</Badge></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Global stream</span><Badge variant={config.global_stream ? 'warning' : 'success'}>{config.global_stream ? 'global (all rows)' : 'scoped'}</Badge></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Service URL</span><span class="font-mono text-xs">{config.service_url ?? '—'}</span></div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle class="flex items-center gap-2"><Database class="size-4" /> Database</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-muted-foreground">Connection</span><span>{db.connection}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Public tables</span><span>{db.tables_total}</span></div>
            </CardContent>
        </Card>
    </div>

    <Card class="mt-4">
        <CardHeader><CardTitle>Synced tables ({config.synced_tables.length})</CardTitle></CardHeader>
        <CardContent class="flex flex-wrap gap-2">
            {#each config.synced_tables as t (t)}
                <Badge variant={config.exposed_sensitive.includes(t) ? 'destructive' : 'muted'}>{t}</Badge>
            {:else}
                <p class="text-sm text-muted-foreground">Could not parse synced tables.</p>
            {/each}
        </CardContent>
    </Card>
</AdminLayout>
