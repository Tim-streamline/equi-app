<script>
    import { onDestroy } from 'svelte';
    import LibraryThumbnail from './LibraryThumbnail.svelte';
    let { value = $bindable(''), mode = $bindable('auto'), busy = $bindable(false), format = 'article', onuploaded, automaticUrl = '' } = $props();
    let localPreview = $state('');
    let error = $state('');
    let input;
    const displayUrl = $derived(mode === 'auto' && ['video', 'course', 'program'].includes(format) ? automaticUrl : value);
    const clearPreview = () => { if (localPreview) URL.revokeObjectURL(localPreview); localPreview = ''; };
    onDestroy(clearPreview);

    async function upload(event) {
        const file = event.currentTarget.files?.[0];
        if (!file) return;
        error = '';
        if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type) || file.size > 10 * 1024 * 1024) {
            error = 'Kies een JPG, PNG, WebP of GIF van maximaal 10 MB.';
            input.value = '';
            return;
        }
        clearPreview();
        localPreview = URL.createObjectURL(file);
        busy = true;
        try {
            const body = new FormData();
            body.append('file', file);
            body.append('purpose', 'thumbnail');
            const response = await fetch('/admin/library/media', { method: 'POST', body, headers: {
                Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            }});
            const data = await response.json();
            if (!response.ok) throw new Error(data.errors?.file?.[0] || data.message || 'Upload mislukt.');
            value = data.asset.url;
            mode = 'manual';
            onuploaded?.(data.asset);
        } catch (exception) {
            error = `Thumbnail uploaden mislukt. ${exception.message}`;
        } finally {
            clearPreview();
            busy = false;
            input.value = '';
        }
    }
</script>

<div class="space-y-3">
    <label for="library-thumbnail" class="text-sm font-medium">Thumbnail</label>
    <LibraryThumbnail src={localPreview || displayUrl} {format} alt="Thumbnail preview" />
    <input id="library-thumbnail" bind:this={input} type="file" accept="image/jpeg,image/png,image/webp,image/gif,.jfif"
        disabled={busy} onchange={upload} class="block w-full text-xs file:mr-2 file:rounded-md file:border file:bg-muted file:px-3 file:py-2" />
    <p class="text-xs text-muted-foreground">JPG, PNG, WebP of GIF, maximaal 10 MB. Uitsnede 4:3. Wijzigingen worden bewaard bij opslaan.</p>
    {#if ['video', 'course', 'program'].includes(format)}
        <p class="text-xs text-muted-foreground">Video: automatisch een beeld uit de eerste seconde. Je kunt dit altijd vervangen door een eigen afbeelding.</p>
        {#if mode !== 'auto'}<button type="button" disabled={busy} class="text-xs underline" onclick={() => { mode = 'auto'; value = automaticUrl; }}>Automatische thumbnail gebruiken</button>{/if}
    {/if}
    {#if displayUrl}<button type="button" disabled={busy} class="block text-xs text-destructive underline" onclick={() => { value = ''; mode = 'none'; }}>Thumbnail verwijderen</button>{/if}
    {#if busy}<p role="status" class="text-xs">Thumbnail uploaden…</p>{/if}
    {#if error}<p role="alert" class="text-sm text-destructive">{error}</p>{/if}
</div>
