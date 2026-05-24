<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardContent, Input, Select, Badge, Button } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { formatDate } from '$lib/utils.js';
    import { Search, Flag, MessageSquare, ThumbsUp } from '@lucide/svelte';

    let { posts, filters, openReports } = $props();
    let q = $state(filters.q ?? '');
    let status = $state(filters.status ?? '');
    let timer;
    function apply() {
        clearTimeout(timer);
        timer = setTimeout(() => router.get('/admin/community', { q, status }, { preserveState: true, replace: true }), 250);
    }
</script>

<AdminLayout title="Community">
    <PageHeader title="Community moderation" description={`${posts.total} posts`}>
        {#snippet actions()}
            <Button variant="outline" href="/admin/reports"><Flag class="size-4" /> Reports {#if openReports}<Badge variant="destructive">{openReports}</Badge>{/if}</Button>
        {/snippet}
    </PageHeader>

    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Search post body…" bind:value={q} oninput={apply} />
                </div>
                <Select class="w-44" bind:value={status} onchange={apply} options={[
                    { value: '', label: 'All' }, { value: 'visible', label: 'Visible' }, { value: 'hidden', label: 'Hidden' },
                    { value: 'locked', label: 'Locked' }, { value: 'pinned', label: 'Pinned' }]} />
            </div>

            <div class="space-y-3">
                {#each posts.data as p (p.id)}
                    <Link href={`/admin/community/post/${p.id}`} class="block rounded-lg border p-4 hover:bg-accent/40">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <span class="font-medium text-foreground">{p.author?.name ?? p.author_name ?? 'Anon'}</span>
                                    {#if p.category}<Badge variant="muted">{p.category.label}</Badge>{/if}
                                    <span>{formatDate(p.created_at)}</span>
                                </div>
                                <p class="mt-1 line-clamp-2 text-sm">{p.body}</p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <Badge variant={statusVariant(p.moderation_status)}>{p.moderation_status}</Badge>
                                {#if p.reports_count}<Badge variant="destructive"><Flag class="size-3" /> {p.reports_count}</Badge>{/if}
                            </div>
                        </div>
                        <div class="mt-2 flex gap-4 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1"><ThumbsUp class="size-3" /> {p.likes_count}</span>
                            <span class="inline-flex items-center gap-1"><MessageSquare class="size-3" /> {p.replies_count}</span>
                            {#if p.has_expert_reply}<Badge variant="success">expert reply</Badge>{/if}
                        </div>
                    </Link>
                {:else}
                    <p class="py-8 text-center text-muted-foreground">No posts.</p>
                {/each}
            </div>
            <Pagination paginator={posts} />
        </CardContent>
    </Card>
</AdminLayout>
