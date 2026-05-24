<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { Link, router, useForm } from '@inertiajs/svelte';
    import { Card, CardHeader, CardTitle, CardContent, Button, Badge, Select, Textarea } from '$lib/components/ui';
    import { statusVariant } from '$lib/badges.js';
    import { formatDate } from '$lib/utils.js';
    import { ArrowLeft, EyeOff, Eye, Lock, Pin, Trash2, RefreshCw, Sparkles } from '@lucide/svelte';

    let { post, reports, therapists } = $props();

    function moderatePost(status) { router.post(`/admin/community/post/${post.id}/moderate`, { status }); }
    function deletePost() { if (confirm('Delete this post and all replies?')) router.delete(`/admin/community/post/${post.id}`); }
    function moderateReply(reply, status) { router.post(`/admin/community/reply/${reply.id}/moderate`, { status }); }
    function deleteReply(reply) { if (confirm('Delete this reply?')) router.delete(`/admin/community/reply/${reply.id}`); }
    function recount() { router.post(`/admin/community/post/${post.id}/recount`); }

    let expertOpen = $state(false);
    const expertForm = useForm({ therapist_id: therapists[0]?.id ?? '', body: '' });
    function submitExpert(e) {
        e.preventDefault();
        $expertForm.post(`/admin/community/post/${post.id}/expert-reply`, { onSuccess: () => (expertOpen = false) });
    }
</script>

<AdminLayout title="Post">
    <Link href="/admin/community" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Back
    </Link>
    <PageHeader title="Post detail">
        {#snippet actions()}
            <Button size="sm" variant="outline" onclick={recount}><RefreshCw class="size-4" /> Recount</Button>
            <Button size="sm" variant="outline" onclick={() => (expertOpen = true)}><Sparkles class="size-4" /> Expert reply</Button>
        {/snippet}
    </PageHeader>

    {#if reports.length}
        <Card class="mb-4 border-destructive/30">
            <CardHeader><CardTitle class="text-destructive">Reports ({reports.length})</CardTitle></CardHeader>
            <CardContent class="space-y-1 text-sm">
                {#each reports as r (r.id)}
                    <div class="flex justify-between border-b py-1 last:border-0">
                        <span><Badge variant="destructive">{r.reason}</Badge> {r.detail ?? ''}</span>
                        <span class="text-xs text-muted-foreground">{r.reporter?.name ?? '—'} · {r.status}</span>
                    </div>
                {/each}
            </CardContent>
        </Card>
    {/if}

    <Card>
        <CardContent class="p-5">
            <div class="mb-2 flex items-center justify-between">
                <div class="text-sm text-muted-foreground">
                    <span class="font-medium text-foreground">{post.author?.name ?? post.author_name}</span> · {formatDate(post.created_at)}
                    {#if post.category}· {post.category.label}{/if}
                </div>
                <Badge variant={statusVariant(post.moderation_status)}>{post.moderation_status}</Badge>
            </div>
            <p class="whitespace-pre-wrap text-sm">{post.body}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <Button size="sm" variant="outline" onclick={() => moderatePost('visible')}><Eye class="size-4" /> Show</Button>
                <Button size="sm" variant="outline" onclick={() => moderatePost('hidden')}><EyeOff class="size-4" /> Hide</Button>
                <Button size="sm" variant="outline" onclick={() => moderatePost('locked')}><Lock class="size-4" /> Lock</Button>
                <Button size="sm" variant="outline" onclick={() => moderatePost('pinned')}><Pin class="size-4" /> Pin</Button>
                <Button size="sm" variant="ghost" onclick={deletePost}><Trash2 class="size-4 text-destructive" /> Delete</Button>
            </div>
        </CardContent>
    </Card>

    <h3 class="mb-3 mt-6 text-sm font-semibold">Replies ({post.replies.length})</h3>
    <div class="space-y-3">
        {#each post.replies as reply (reply.id)}
            <Card>
                <CardContent class="p-4">
                    <div class="mb-1 flex items-center justify-between">
                        <div class="text-sm">
                            <span class="font-medium">{reply.author_name ?? reply.author_user?.name ?? reply.author_therapist?.name}</span>
                            {#if reply.author_is_expert}<Badge variant="success">expert</Badge>{/if}
                        </div>
                        <Badge variant={statusVariant(reply.moderation_status)}>{reply.moderation_status}</Badge>
                    </div>
                    <p class="whitespace-pre-wrap text-sm">{reply.body}</p>
                    <div class="mt-2 flex gap-2">
                        {#if reply.moderation_status === 'hidden'}
                            <Button size="sm" variant="outline" onclick={() => moderateReply(reply, 'visible')}><Eye class="size-4" /> Show</Button>
                        {:else}
                            <Button size="sm" variant="outline" onclick={() => moderateReply(reply, 'hidden')}><EyeOff class="size-4" /> Hide</Button>
                        {/if}
                        <Button size="sm" variant="ghost" onclick={() => deleteReply(reply)}><Trash2 class="size-4 text-destructive" /></Button>
                    </div>
                </CardContent>
            </Card>
        {:else}
            <p class="text-sm text-muted-foreground">No replies.</p>
        {/each}
    </div>

    <Modal bind:open={expertOpen} title="Post expert reply" description="Reply as a verified therapist.">
        <form id="x-form" onsubmit={submitExpert} class="space-y-4">
            <Field label="Therapist" error={$expertForm.errors.therapist_id}>
                <Select bind:value={$expertForm.therapist_id} options={therapists.map((t) => ({ value: t.id, label: t.name }))} />
            </Field>
            <Field label="Reply" error={$expertForm.errors.body}><Textarea class="min-h-32" bind:value={$expertForm.body} /></Field>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (expertOpen = false)}>Cancel</Button>
            <Button type="submit" form="x-form" disabled={$expertForm.processing}>Post reply</Button>
        {/snippet}
    </Modal>
</AdminLayout>
