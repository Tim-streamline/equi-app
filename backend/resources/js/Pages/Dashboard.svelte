<script>
    import AdminLayout from '../Layouts/AdminLayout.svelte';
    import StatCard from '$lib/components/StatCard.svelte';
    import { Link } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Badge } from '$lib/components/ui';
    import { formatNumber, formatCents, formatDate } from '$lib/utils.js';
    import { statusVariant } from '$lib/badges.js';
    import { Users, Heart, ScanLine, MessagesSquare, CalendarClock, CreditCard, ClipboardList, Flag } from '@lucide/svelte';

    let { stats, trend, planBreakdown, recentScans, recentSignups, recentAudit } = $props();

    const maxTrend = $derived(Math.max(1, ...trend.map((t) => t.value)));
    const planTotal = $derived(Math.max(1, planBreakdown.reduce((a, p) => a + p.total, 0)));
</script>

<AdminLayout title="Dashboard">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total users" value={formatNumber(stats.users)} hint={`${stats.users_new_week} new this week`} icon={Users} />
        <StatCard label="Active horses" value={formatNumber(stats.horses)} icon={Heart} accent="success" />
        <StatCard label="Active protocols" value={formatNumber(stats.protocols_active)} icon={ClipboardList} accent="primary" />
        <StatCard label="MRR" value={formatCents(stats.mrr_cents)} hint={`${stats.subs_active} active subs`} icon={CreditCard} accent="success" />
        <StatCard label="Scans" value={formatNumber(stats.scans)} hint={`${stats.scans_week} this week`} icon={ScanLine} accent="muted" />
        <StatCard label="Community posts" value={formatNumber(stats.community_posts)} icon={MessagesSquare} accent="muted" />
        <StatCard label="Pending bookings" value={formatNumber(stats.bookings_pending)} icon={CalendarClock} accent="warning" />
        <StatCard label="Open reports" value={formatNumber(stats.reports_open)} icon={Flag} accent={stats.reports_open ? 'destructive' : 'muted'} />
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <Card class="lg:col-span-2">
            <CardHeader><CardTitle>Signups — last 12 weeks</CardTitle></CardHeader>
            <CardContent>
                <div class="flex h-44 items-end gap-2">
                    {#each trend as week (week.label)}
                        <div class="flex flex-1 flex-col items-center gap-1.5">
                            <div class="flex w-full flex-1 items-end">
                                <div
                                    class="w-full rounded-t bg-primary/80 transition-all"
                                    style={`height: ${Math.round((week.value / maxTrend) * 100)}%; min-height: 2px;`}
                                    title={`${week.value} signups`}
                                ></div>
                            </div>
                            <span class="text-[10px] text-muted-foreground">{week.label}</span>
                        </div>
                    {/each}
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Active plans</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                {#each planBreakdown as plan (plan.name)}
                    <div>
                        <div class="mb-1 flex justify-between text-sm">
                            <span>{plan.name}</span>
                            <span class="text-muted-foreground">{plan.total}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-muted">
                            <div class="h-full rounded-full bg-primary" style={`width: ${(plan.total / planTotal) * 100}%`}></div>
                        </div>
                    </div>
                {:else}
                    <p class="text-sm text-muted-foreground">No active subscriptions yet.</p>
                {/each}
            </CardContent>
        </Card>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <Card>
            <CardHeader><CardTitle>Recent signups</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                {#each recentSignups as u (u.id)}
                    <Link href={`/admin/users/${u.id}`} class="flex items-center justify-between gap-2 text-sm hover:text-primary">
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{u.name}</span>
                            <span class="block truncate text-xs text-muted-foreground">{u.email}</span>
                        </span>
                        <Badge variant={u.onboarded ? 'success' : 'muted'}>{u.onboarded ? 'onboarded' : 'pending'}</Badge>
                    </Link>
                {/each}
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Recent scans</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                {#each recentScans as s (s.id)}
                    <div class="flex items-center justify-between gap-2 text-sm">
                        <span class="min-w-0">
                            <span class="block truncate font-medium">{s.product_name ?? 'Unknown product'}</span>
                            <span class="block truncate text-xs text-muted-foreground">{s.brand} · {s.user?.name}</span>
                        </span>
                        <Badge variant={statusVariant(s.rating)}>{s.score}</Badge>
                    </div>
                {/each}
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Recent admin activity</CardTitle></CardHeader>
            <CardContent class="space-y-2.5">
                {#each recentAudit as log (log.id)}
                    <div class="text-sm">
                        <span class="font-medium capitalize">{log.action.replace(/_/g, ' ')}</span>
                        <span class="text-muted-foreground"> · {log.target_label ?? log.target_type ?? '—'}</span>
                        <div class="text-xs text-muted-foreground/80">
                            {log.actor_name ?? 'system'} · {formatDate(log.created_at, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                        </div>
                    </div>
                {:else}
                    <p class="text-sm text-muted-foreground">No activity yet.</p>
                {/each}
            </CardContent>
        </Card>
    </div>
</AdminLayout>
