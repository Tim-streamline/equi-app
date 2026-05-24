<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Badge, Input, Select, Textarea, Separator } from '$lib/components/ui';
    import { formatDate, formatCents } from '$lib/utils.js';
    import { statusVariant } from '$lib/badges.js';
    import { ArrowLeft, Pencil, KeyRound, Ban, RotateCcw, ShieldAlert, Download } from '@lucide/svelte';

    let { user, activeRestriction } = $props();

    let restrictOpen = $state(false);
    const restrictForm = useForm({ type: 'warning', reason: '', days: '' });
    function submitRestrict(e) {
        e.preventDefault();
        $restrictForm.post(`/admin/users/${user.id}/restrict`, { onSuccess: () => (restrictOpen = false) });
    }

    function disable() {
        if (confirm('Disable this account? The user will be signed out of the app.')) {
            router.post(`/admin/users/${user.id}/disable`);
        }
    }
    function restore() {
        router.post(`/admin/users/${user.id}/restore`);
    }
    function resetPassword() {
        if (confirm('Reset password and generate a temporary one?')) {
            router.post(`/admin/users/${user.id}/reset-password`);
        }
    }
    function requestExport(format) {
        router.post(`/admin/users/${user.id}/data-export`, { format });
    }
</script>

<AdminLayout title="User detail">
    <Link href="/admin/users" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Back to users
    </Link>

    <PageHeader title={user.name} description={user.email}>
        {#snippet actions()}
            <Button variant="outline" href={`/admin/users/${user.id}/edit`}><Pencil class="size-4" /> Edit</Button>
            <Button variant="outline" onclick={resetPassword}><KeyRound class="size-4" /> Reset password</Button>
            <Button variant="outline" onclick={() => (restrictOpen = true)}><ShieldAlert class="size-4" /> Restrict</Button>
            {#if user.disabled_at}
                <Button onclick={restore}><RotateCcw class="size-4" /> Restore</Button>
            {:else}
                <Button variant="destructive" onclick={disable}><Ban class="size-4" /> Disable</Button>
            {/if}
        {/snippet}
    </PageHeader>

    {#if user.disabled_at}
        <div class="mb-4 rounded-md border border-destructive/30 bg-destructive/10 px-4 py-2 text-sm text-destructive">
            Account disabled on {formatDate(user.disabled_at)}.
        </div>
    {/if}
    {#if activeRestriction}
        <div class="mb-4 rounded-md border border-warning/30 bg-warning/10 px-4 py-2 text-sm text-warning">
            Active {activeRestriction.type} · {activeRestriction.reason ?? 'no reason given'}
            {activeRestriction.expires_at ? `· until ${formatDate(activeRestriction.expires_at)}` : '· permanent'}
        </div>
    {/if}

    <div class="grid gap-4 lg:grid-cols-3">
        <Card>
            <CardHeader><CardTitle>Profile</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-muted-foreground">Locale</span><span>{user.locale}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Units</span><span>{user.units_system}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Notifications</span><span>{user.notifications_on ? 'On' : 'Off'}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Onboarded</span><span>{user.onboarded_at ? formatDate(user.onboarded_at) : 'No'}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Joined</span><span>{formatDate(user.created_at)}</span></div>
                {#if user.admin_note}<Separator class="my-2" /><p class="text-muted-foreground">Note: {user.admin_note}</p>{/if}
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Subscription</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                {#if user.subscription}
                    <div class="flex justify-between"><span class="text-muted-foreground">Plan</span><span>{user.subscription.plan?.name}</span></div>
                    <div class="flex justify-between items-center"><span class="text-muted-foreground">Status</span><Badge variant={statusVariant(user.subscription.status)}>{user.subscription.status}</Badge></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">Price</span><span>{formatCents(user.subscription.price_cents, user.subscription.currency)}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">Renews</span><span>{formatDate(user.subscription.renews_at)}</span></div>
                    <div class="flex justify-between"><span class="text-muted-foreground">Max horses</span><span>{user.subscription.max_horses}</span></div>
                {:else}
                    <p class="text-muted-foreground">Free plan — no active subscription.</p>
                {/if}
            </CardContent>
        </Card>

        <Card>
            <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-muted-foreground">Horses</span><span>{user.horses.length}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Scans</span><span>{user.scans_count}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Posts</span><span>{user.community_posts_count}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Replies</span><span>{user.community_replies_count}</span></div>
                <div class="flex justify-between"><span class="text-muted-foreground">Chat sessions</span><span>{user.chat_sessions_count}</span></div>
                <Separator class="my-2" />
                <div class="flex gap-2">
                    <Button size="sm" variant="outline" onclick={() => requestExport('csv')}><Download class="size-4" /> CSV</Button>
                    <Button size="sm" variant="outline" onclick={() => requestExport('pdf')}><Download class="size-4" /> PDF</Button>
                </div>
            </CardContent>
        </Card>
    </div>

    <Card class="mt-4">
        <CardHeader><CardTitle>Horses</CardTitle></CardHeader>
        <CardContent class="space-y-2">
            {#each user.horses as h (h.id)}
                <Link href={`/admin/horses/${h.id}`} class="flex items-center justify-between rounded-md border px-3 py-2 text-sm hover:bg-accent">
                    <span><span class="font-medium">{h.name}</span> <span class="text-muted-foreground">· {h.breed ?? 'unknown breed'}</span></span>
                    <span class="text-xs text-muted-foreground">{h.observations_count} obs · {h.scans_count} scans</span>
                </Link>
            {:else}
                <p class="text-sm text-muted-foreground">No horses.</p>
            {/each}
        </CardContent>
    </Card>

    {#if user.data_exports?.length}
        <Card class="mt-4">
            <CardHeader><CardTitle>Data exports</CardTitle></CardHeader>
            <CardContent class="space-y-2 text-sm">
                {#each user.data_exports as ex (ex.id)}
                    <div class="flex items-center justify-between border-b py-1 last:border-0">
                        <span class="uppercase">{ex.format} · {formatDate(ex.requested_at)}</span>
                        <Badge variant={ex.completed_at ? 'success' : 'warning'}>{ex.completed_at ? 'completed' : 'pending'}</Badge>
                    </div>
                {/each}
            </CardContent>
        </Card>
    {/if}

    <Modal bind:open={restrictOpen} title="Apply restriction" description="Warn, mute, or ban this user.">
        <form id="restrict-form" onsubmit={submitRestrict} class="space-y-4">
            <Field label="Type" error={$restrictForm.errors.type}>
                <Select bind:value={$restrictForm.type} options={[
                    { value: 'warning', label: 'Warning' },
                    { value: 'mute', label: 'Mute' },
                    { value: 'ban', label: 'Ban' },
                ]} />
            </Field>
            <Field label="Duration (days, blank = permanent)" error={$restrictForm.errors.days}>
                <Input type="number" min="1" bind:value={$restrictForm.days} placeholder="e.g. 7" />
            </Field>
            <Field label="Reason" error={$restrictForm.errors.reason}>
                <Textarea bind:value={$restrictForm.reason} placeholder="Visible in the audit log" />
            </Field>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (restrictOpen = false)}>Cancel</Button>
            <Button type="submit" form="restrict-form" disabled={$restrictForm.processing}>Apply</Button>
        {/snippet}
    </Modal>
</AdminLayout>
