<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import StatCard from '$lib/components/StatCard.svelte';
    import { Card, CardHeader, CardTitle, CardContent, Badge, Button } from '$lib/components/ui';
    import { router } from '@inertiajs/svelte';
    import { formatNumber, formatDateTime } from '$lib/utils.js';
    import { AlertTriangle, CheckCircle2, Database, Users, Activity, Timer, HardDriveDownload, RefreshCw, WifiOff } from '@lucide/svelte';

    let { config, db, live } = $props();

    let refreshing = $state(false);
    let autoRefresh = $state(true);

    function refresh() {
        router.reload({
            only: ['live'],
            preserveScroll: true,
            onStart: () => (refreshing = true),
            onFinish: () => (refreshing = false),
        });
    }

    // Poll the live metrics every 10s while auto-refresh is on. The effect
    // re-runs when `autoRefresh` flips and tears the interval down on cleanup.
    $effect(() => {
        if (!autoRefresh) return;
        const id = setInterval(refresh, 10000);
        return () => clearInterval(id);
    });

    function bytes(n) {
        if (n == null) return '—';
        const u = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return `${n.toFixed(n < 10 && i > 0 ? 1 : 0)} ${u[i]}`;
    }
    const lag = (s) => (s == null ? '—' : `${Number(s).toFixed(1)} s`);
</script>

<AdminLayout title="Sync health">
    <PageHeader title="PowerSync health" description="Live runtime stats, stream configuration and sensitive-table exposure">
        {#snippet actions()}
            <label class="flex items-center gap-2 text-sm text-muted-foreground">
                <input type="checkbox" bind:checked={autoRefresh} class="size-4 rounded border-input" /> Auto-refresh
            </label>
            <Button variant="outline" size="sm" onclick={refresh} disabled={refreshing}>
                <RefreshCw class={'size-4 ' + (refreshing ? 'animate-spin' : '')} /> Refresh
            </Button>
        {/snippet}
    </PageHeader>

    <!-- Live runtime -->
    {#if live?.reachable}
        <div class="mb-2 flex items-center gap-2 text-xs text-muted-foreground">
            <span class="relative flex size-2">
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-75"></span>
                <span class="relative inline-flex size-2 rounded-full bg-success"></span>
            </span>
            Live from PowerSync · updated {formatDateTime(live.scraped_at)}
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Connected clients" value={formatNumber(live.connected_clients)} hint="active sync streams now" icon={Users} accent={live.connected_clients > 0 ? 'success' : 'muted'} />
            <StatCard label="Replication lag" value={lag(live.replication_lag_seconds)} icon={Timer} accent={live.replication_lag_seconds > 5 ? 'warning' : 'muted'} />
            <StatCard label="Rows replicated" value={formatNumber(live.rows_replicated ?? 0)} hint="since service start" icon={Activity} accent="muted" />
            <StatCard label="Data replicated" value={bytes(live.data_replicated_bytes)} icon={HardDriveDownload} accent="muted" />
        </div>
    {:else}
        <div class="mb-4 flex items-start gap-3 rounded-lg border border-warning/30 bg-warning/10 p-4 text-sm text-warning">
            <WifiOff class="mt-0.5 size-5 shrink-0" />
            <div>
                <div class="font-medium">PowerSync metrics unreachable</div>
                <div>Could not scrape <code class="rounded bg-warning/15 px-1">{config.service_url ?? 'the service'}</code>. The service may be down, or <code class="rounded bg-warning/15 px-1">telemetry.prometheus_port</code> isn't enabled.</div>
            </div>
        </div>
    {/if}

    <!-- Sensitive exposure -->
    {#if config.exposed_sensitive?.length}
        <div class="my-4 flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
            <AlertTriangle class="mt-0.5 size-5 shrink-0" />
            <div>
                <div class="font-medium">{config.exposed_sensitive.length} sensitive table(s) are in the sync stream</div>
                <div>These should be server-only before production: {config.exposed_sensitive.join(', ')}</div>
            </div>
        </div>
    {:else}
        <div class="my-4 flex items-center gap-3 rounded-lg border border-success/30 bg-success/10 p-4 text-sm text-success">
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
