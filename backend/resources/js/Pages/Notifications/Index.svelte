<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import StatCard from '$lib/components/StatCard.svelte';
    import { Card, CardHeader, CardTitle, CardContent } from '$lib/components/ui';
    import { Users, BellRing, BellOff, Smartphone } from '@lucide/svelte';

    let { segments, channels } = $props();
    const pct = (n) => (segments.total_users ? Math.round((n / segments.total_users) * 100) : 0);
</script>

<AdminLayout title="Notifications">
    <PageHeader title="Notifications & messaging" description="Opt-in segments. Campaign sending needs dedicated template/campaign tables." />
    <div class="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total users" value={segments.total_users} icon={Users} accent="muted" />
        <StatCard label="Opted in" value={segments.opted_in} hint={`${pct(segments.opted_in)}%`} icon={BellRing} accent="success" />
        <StatCard label="Opted out" value={segments.opted_out} icon={BellOff} accent="warning" />
        <StatCard label="Push tokens" value={segments.push_tokens} icon={Smartphone} accent="primary" />
    </div>

    <Card>
        <CardHeader><CardTitle>Channel opt-in</CardTitle></CardHeader>
        <CardContent class="space-y-3">
            {#each channels as ch (ch.name)}
                <div>
                    <div class="mb-1 flex justify-between text-sm"><span>{ch.name}</span><span class="text-muted-foreground">{ch.optedIn} users</span></div>
                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full bg-primary" style={`width:${pct(ch.optedIn)}%`}></div>
                    </div>
                </div>
            {/each}
        </CardContent>
    </Card>

    <div class="mt-4 rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
        Campaign builder, scheduling and delivery tracking are planned. They require the
        <code class="rounded bg-muted px-1">notification_templates</code>,
        <code class="rounded bg-muted px-1">notification_campaigns</code> and
        <code class="rounded bg-muted px-1">notification_deliveries</code> tables from the admin spec.
    </div>
</AdminLayout>
