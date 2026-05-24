<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Field from '$lib/components/Field.svelte';
    import MediaUploader from '$lib/components/MediaUploader.svelte';
    import { Link, useForm } from '@inertiajs/svelte';
    import { tick } from 'svelte';
    import { Card, CardContent, CardHeader, CardTitle, Button, Input, Textarea, Select } from '$lib/components/ui';
    import { cn } from '$lib/utils.js';
    import { ArrowLeft } from '@lucide/svelte';

    let { item, categories, focusTopics, therapists } = $props();
    const isNew = !item;

    let bodyEl = $state(null);

    // Build the markdown/HTML snippet embedded into the article body. Images
    // use markdown; video/audio use HTML5 tags (supported by the app's
    // markdown renderer for raw media).
    function mediaSnippet(a) {
        if (a.type === 'image') return `\n\n![${a.original_name}](${a.url})\n\n`;
        if (a.type === 'video') return `\n\n<video src="${a.url}" controls width="100%"></video>\n\n`;
        return `\n\n<audio src="${a.url}" controls></audio>\n\n`;
    }

    // Insert a media reference at the caret (or end) of the body field so
    // several files can be placed exactly where they belong in the article.
    function insertMedia(asset) {
        const snippet = mediaSnippet(asset);
        const start = bodyEl?.selectionStart ?? $form.body.length;
        const end = bodyEl?.selectionEnd ?? start;
        $form.body = $form.body.slice(0, start) + snippet + $form.body.slice(end);
        tick().then(() => {
            if (!bodyEl) return;
            const pos = start + snippet.length;
            bodyEl.focus();
            bodyEl.setSelectionRange(pos, pos);
        });
    }

    const form = useForm({
        title: item?.title ?? '',
        slug: item?.slug ?? '',
        kind: item?.kind ?? 'Kruid',
        format: item?.format ?? 'article',
        description: item?.description ?? '',
        body: item?.body ?? '',
        video_url: item?.video_url ?? '',
        hero_image_url: item?.hero_image_url ?? '',
        duration_label: item?.duration_label ?? '',
        author_therapist_id: item?.author_therapist_id ?? '',
        published_at: item?.published_at ? item.published_at.slice(0, 10) : '',
        is_plus: item?.is_plus ?? false,
        is_featured: item?.is_featured ?? false,
        order: item?.order ?? 0,
        category_ids: item?.categories?.map((c) => c.id) ?? [],
        focus_ids: item?.focus_topics?.map((f) => f.id) ?? [],
    });

    function toggle(arr, id) {
        return arr.includes(id) ? arr.filter((x) => x !== id) : [...arr, id];
    }
    function submit(e) {
        e.preventDefault();
        if (isNew) $form.post('/admin/library');
        else $form.put(`/admin/library/${item.id}`);
    }
</script>

<AdminLayout title={isNew ? 'New library item' : 'Edit library item'}>
    <Link href="/admin/library" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Back to library
    </Link>
    <PageHeader title={isNew ? 'New library item' : `Edit: ${item.title}`} />

    <form onsubmit={submit} class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <Card>
                <CardContent class="space-y-4 p-6">
                    <Field label="Title" error={$form.errors.title}><Input bind:value={$form.title} /></Field>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <Field label="Format" error={$form.errors.format}>
                            <Select bind:value={$form.format} options={[
                                { value: 'article', label: 'Article' }, { value: 'video', label: 'Video' },
                                { value: 'course', label: 'Course' }, { value: 'program', label: 'Program' }]} />
                        </Field>
                        <Field label="Kind" error={$form.errors.kind}><Input bind:value={$form.kind} placeholder="Kruid, Voeding…" /></Field>
                    </div>
                    <Field label="Slug" hint="Leave blank to auto-generate" error={$form.errors.slug}><Input bind:value={$form.slug} /></Field>
                    <Field label="Description" error={$form.errors.description}><Textarea bind:value={$form.description} /></Field>
                    <Field label="Body (markdown)" hint="Use the Media panel to insert images, video and audio at the caret." error={$form.errors.body}>
                        <textarea
                            bind:this={bodyEl}
                            bind:value={$form.body}
                            class={cn(
                                'flex min-h-64 w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            )}
                        ></textarea>
                    </Field>
                    {#if $form.format === 'video'}
                        <Field label="Video URL" error={$form.errors.video_url}><Input bind:value={$form.video_url} /></Field>
                    {/if}
                </CardContent>
            </Card>
        </div>

        <div class="space-y-4">
            <Card>
                <CardHeader><CardTitle>Publishing</CardTitle></CardHeader>
                <CardContent class="space-y-4">
                    <Field label="Publish date" hint="Blank = draft" error={$form.errors.published_at}><Input type="date" bind:value={$form.published_at} /></Field>
                    <Field label="Author" error={$form.errors.author_therapist_id}>
                        <Select bind:value={$form.author_therapist_id} placeholder="None"
                            options={therapists.map((t) => ({ value: t.id, label: t.name }))} />
                    </Field>
                    <Field label="Hero image URL"><Input bind:value={$form.hero_image_url} /></Field>
                    <Field label="Duration label"><Input bind:value={$form.duration_label} placeholder="8 min" /></Field>
                    <Field label="Order"><Input type="number" bind:value={$form.order} /></Field>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.is_featured} class="size-4 rounded border-input" /> Featured</label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$form.is_plus} class="size-4 rounded border-input" /> Plus only</label>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Media</CardTitle>
                    <p class="text-xs text-muted-foreground">Upload images, video and audio, then click ＋ to embed them in the body.</p>
                </CardHeader>
                <CardContent>
                    <MediaUploader libraryItemId={item?.id ?? null} initial={item?.media ?? []} oninsert={insertMedia} />
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Categories</CardTitle></CardHeader>
                <CardContent class="flex flex-wrap gap-2">
                    {#each categories as c (c.id)}
                        <button type="button" onclick={() => ($form.category_ids = toggle($form.category_ids, c.id))}
                            class={'rounded-full border px-3 py-1 text-xs ' + ($form.category_ids.includes(c.id) ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent')}>
                            {c.label}
                        </button>
                    {/each}
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Focus topics</CardTitle></CardHeader>
                <CardContent class="flex flex-wrap gap-2">
                    {#each focusTopics as f (f.id)}
                        <button type="button" onclick={() => ($form.focus_ids = toggle($form.focus_ids, f.id))}
                            class={'rounded-full border px-3 py-1 text-xs ' + ($form.focus_ids.includes(f.id) ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-accent')}>
                            {f.title}
                        </button>
                    {/each}
                </CardContent>
            </Card>

            <div class="flex gap-2">
                <Button type="submit" class="flex-1" disabled={$form.processing}>{isNew ? 'Create' : 'Save'}</Button>
                <Button variant="outline" href="/admin/library">Cancel</Button>
            </div>
        </div>
    </form>
</AdminLayout>
