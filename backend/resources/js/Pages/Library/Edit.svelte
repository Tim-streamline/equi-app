<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Field from '$lib/components/Field.svelte';
    import MediaUploader from '$lib/components/MediaUploader.svelte';
    import ThumbnailUploader from '$lib/components/ThumbnailUploader.svelte';
    import MarkdownEditor from '$lib/components/MarkdownEditor.svelte';
    import LibraryItemPreviewModal from '$lib/components/LibraryItemPreviewModal.svelte';
    import { Link, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, CardHeader, CardTitle, Button, Input, Textarea, Select } from '$lib/components/ui';
    import { ArrowLeft, Eye } from '@lucide/svelte';

    let { item, categories, therapists, automaticThumbnailUrl, videoDurationMinutes } = $props();
    const isNew = !item;
    // Use the editor's local calendar date, avoiding UTC shifts near midnight.
    const today = new Date();
    const publishDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    const defaultAuthorId = therapists.find((therapist) => !therapist.archived_at && therapist.name === 'Shelley Meeuwsen')?.id ?? '';

    let bodyEditor = $state(null);
    let mediaBusy = $state(false);
    let thumbnailBusy = $state(false);
    let automaticUrl = $state(automaticThumbnailUrl ?? '');
    function uploaded(asset) {
        $form.media_ids = [...new Set([...$form.media_ids, asset.id])];
        if (asset.type === 'video') {
            automaticUrl ||= asset.thumbnail_url ?? '';
            if ($form.thumbnail_mode === 'auto' && !$form.hero_image_url && ['video', 'course', 'program'].includes($form.format)) $form.hero_image_url = automaticUrl;
        }
    }

    let previewOpen = $state(false);

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
        if (bodyEditor) bodyEditor.insertText(mediaSnippet(asset));
        else $form.body += mediaSnippet(asset);
    }

    const form = useForm({
        title: item?.title ?? '',
        slug: item?.slug ?? '',
        format: item?.format ?? 'article',
        description: item?.description ?? '',
        body: item?.body ?? '',
        hero_image_url: item?.hero_image_url ?? '',
        thumbnail_mode: item?.thumbnail_mode ?? 'auto',
        media_ids: [],
        duration_label: item?.duration_label ?? '',
        duration_minutes: videoDurationMinutes ?? null,
        author_therapist_id: isNew ? defaultAuthorId : (item.author_therapist_id ?? ''),
        published_at: isNew ? publishDate : (item.published_at ? item.published_at.slice(0, 10) : ''),
        credit_cost: isNew ? 1 : (item.credit_cost ?? 0),
        is_plus: isNew ? true : (item.is_plus ?? false),
        is_featured: item?.is_featured ?? false,
        order: item?.order ?? 0,
        category_ids: item?.categories?.map((c) => c.id) ?? [],
    });

    function toggle(arr, id) {
        return arr.includes(id) ? arr.filter((x) => x !== id) : [...arr, id];
    }
    function submit(e) {
        e.preventDefault();
        if (thumbnailBusy || mediaBusy || $form.processing) return;
        $form.transform((data) => ({ ...data, duration_minutes: data.duration_minutes ?? null }));
        if (isNew) $form.post('/admin/library');
        else $form.put(`/admin/library/${item.id}`);
    }
</script>

<AdminLayout title={isNew ? 'New library item' : 'Edit library item'}>
    <Link href="/admin/library" class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="size-4" /> Back to library
    </Link>
    <PageHeader title={isNew ? 'New library item' : `Edit: ${item.title}`}>
        {#snippet actions()}
            <Button variant="outline" onclick={() => (previewOpen = true)}><Eye class="size-4" /> Preview</Button>
        {/snippet}
    </PageHeader>

    <form onsubmit={submit} class="grid gap-4 lg:grid-cols-3">
        <div class="min-w-0 space-y-4 lg:col-span-2">
            <Card>
                <CardContent class="space-y-4 p-6">
                    <Field label="Title" error={$form.errors.title}><Input bind:value={$form.title} /></Field>
                    <Field label="Format" error={$form.errors.format}>
                        <Select bind:value={$form.format} options={[
                            { value: 'article', label: 'Article' }, { value: 'video', label: 'Video' },
                            { value: 'audio', label: 'Audio' }, { value: 'podcast', label: 'Podcast' }, { value: 'course', label: 'Course' }, { value: 'program', label: 'Program' }]} />
                    </Field>
                    <Field label="Slug" hint="Leave blank to auto-generate" error={$form.errors.slug}><Input bind:value={$form.slug} /></Field>
                    <Field label="Description" error={$form.errors.description}><Textarea bind:value={$form.description} /></Field>
                    <Field label="Body (markdown)" hint="Use the Media panel to insert images, video and audio at the caret." error={$form.errors.body}>
                        <MarkdownEditor bind:this={bodyEditor} bind:value={$form.body} />
                    </Field>
                </CardContent>
            </Card>
        </div>

        <div class="space-y-4">
            <Card>
                <CardHeader><CardTitle>Publishing</CardTitle></CardHeader>
                <CardContent class="space-y-4">
                    <Field label="Credits (0 = gratis)" error={$form.errors.credit_cost}><Input type="number" min="0" bind:value={$form.credit_cost} /></Field>
                    <Field label="Publish date" hint="Blank = draft" error={$form.errors.published_at}><Input type="date" bind:value={$form.published_at} /></Field>
                    <Field label="Author" error={$form.errors.author_therapist_id}>
                        <Select bind:value={$form.author_therapist_id}
                            options={[{ value: '', label: 'None' }, ...therapists.map((t) => ({ value: t.id, label: `${t.name}${t.archived_at ? ' (gearchiveerd)' : ''}` }))]} />
                    </Field>
                    <ThumbnailUploader bind:value={$form.hero_image_url} bind:mode={$form.thumbnail_mode} bind:busy={thumbnailBusy} format={$form.format} onuploaded={uploaded} {automaticUrl} />
                    {#if $form.errors.hero_image_url}<p role="alert" class="text-sm text-destructive">{$form.errors.hero_image_url}</p>{/if}
                    {#if $form.format === 'video'}
                        <Field label="Videoduur" hint="Optioneel. Positief getal, maximaal twee decimalen." error={$form.errors.duration_minutes}>
                            <div class="flex items-center gap-2">
                                <Input type="number" min="0.01" max="35791394" step="0.01" bind:value={$form.duration_minutes} placeholder="12" aria-label="Videoduur in minuten" />
                                <span class="text-sm text-muted-foreground">min</span>
                            </div>
                        </Field>
                    {:else}
                        <Field label="Duration label" error={$form.errors.duration_label}><Input bind:value={$form.duration_label} placeholder="8 min" /></Field>
                    {/if}
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
                    <MediaUploader libraryItemId={item?.id ?? null} initial={item?.media ?? []} oninsert={insertMedia} onuploaded={uploaded} bind:busy={mediaBusy} onremoved={(asset) => { $form.media_ids = $form.media_ids.filter((id) => id !== asset.id); if ($form.hero_image_url === asset.thumbnail_url) $form.hero_image_url = ''; }} />
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

            {#if Object.keys($form.errors).length}<p role="alert" class="text-sm text-destructive">Opslaan mislukt: {Object.values($form.errors).join(" ")}</p>{/if}
            <div class="flex gap-2">
                <Button type="submit" class="flex-1" disabled={$form.processing || thumbnailBusy || mediaBusy}>{isNew ? 'Create' : 'Save'}</Button>
                <Button variant="outline" href="/admin/library">Cancel</Button>
            </div>
        </div>
    </form>

    <LibraryItemPreviewModal
        open={previewOpen}
        onclose={() => (previewOpen = false)}
        title={$form.title}
        format={$form.format}
        description={$form.description}
        body={$form.body}
        heroImageUrl={$form.hero_image_url}
        durationLabel={$form.format === 'video' ? ($form.duration_minutes > 0 ? `${$form.duration_minutes} min` : '') : $form.duration_label}
        authorName={therapists.find((therapist) => therapist.id === $form.author_therapist_id)?.name ?? ''}
        publishedAt={$form.published_at}
        categories={categories.filter((category) => $form.category_ids.includes(category.id))}
    />
</AdminLayout>
