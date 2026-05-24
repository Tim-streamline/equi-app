<script>
    import { Button } from '$lib/components/ui';
    import { cn } from '$lib/utils.js';
    import { Upload, Image, Film, Music, Trash2, Plus, Loader2 } from '@lucide/svelte';

    let { libraryItemId = null, initial = [], oninsert } = $props();

    let media = $state([...initial]);
    let uploading = $state(false);
    let error = $state('');
    let dragging = $state(false);
    let input;

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const icons = { image: Image, video: Film, audio: Music };

    function humanSize(bytes) {
        if (!bytes) return '';
        const u = ['B', 'KB', 'MB', 'GB'];
        let i = 0, n = bytes;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return `${n.toFixed(n < 10 && i > 0 ? 1 : 0)} ${u[i]}`;
    }

    async function uploadOne(file) {
        const body = new FormData();
        body.append('file', file);
        if (libraryItemId) body.append('library_item_id', libraryItemId);

        const res = await fetch('/admin/library/media', {
            method: 'POST',
            body,
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        });

        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.message || `Upload failed for ${file.name}`);
        }
        const { asset } = await res.json();
        media = [asset, ...media];
    }

    async function handleFiles(fileList) {
        const files = Array.from(fileList);
        if (!files.length) return;
        error = '';
        uploading = true;
        try {
            for (const f of files) await uploadOne(f);
        } catch (e) {
            error = e.message;
        } finally {
            uploading = false;
            if (input) input.value = '';
        }
    }

    function onDrop(e) {
        e.preventDefault();
        dragging = false;
        handleFiles(e.dataTransfer.files);
    }

    async function remove(asset) {
        if (!confirm(`Delete ${asset.original_name}? It will no longer load in any article that references it.`)) return;
        const res = await fetch(`/admin/library/media/${asset.id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        });
        if (res.ok) media = media.filter((m) => m.id !== asset.id);
    }
</script>

<div>
    <div
        role="button"
        tabindex="0"
        class={cn(
            'flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed p-6 text-center text-sm transition-colors',
            dragging ? 'border-primary bg-primary/5' : 'border-input hover:border-primary/60',
        )}
        onclick={() => input?.click()}
        onkeydown={(e) => (e.key === 'Enter' || e.key === ' ') && input?.click()}
        ondragover={(e) => { e.preventDefault(); dragging = true; }}
        ondragleave={() => (dragging = false)}
        ondrop={onDrop}
    >
        {#if uploading}
            <Loader2 class="size-6 animate-spin text-primary" />
            <span class="text-muted-foreground">Uploading…</span>
        {:else}
            <Upload class="size-6 text-muted-foreground" />
            <span class="font-medium">Drop files or click to upload</span>
            <span class="text-xs text-muted-foreground">Images, video and audio · multiple files</span>
        {/if}
    </div>
    <input
        bind:this={input}
        type="file"
        class="hidden"
        multiple
        accept="image/*,video/*,audio/*"
        onchange={(e) => handleFiles(e.currentTarget.files)}
    />

    {#if error}<p class="mt-2 text-xs text-destructive">{error}</p>{/if}

    {#if media.length}
        <div class="mt-3 grid grid-cols-2 gap-2">
            {#each media as asset (asset.id)}
                {@const Icon = icons[asset.type]}
                <div class="group relative overflow-hidden rounded-md border">
                    <div class="flex h-24 items-center justify-center bg-muted">
                        {#if asset.type === 'image'}
                            <img src={asset.url} alt={asset.original_name} class="h-full w-full object-cover" />
                        {:else}
                            <Icon class="size-8 text-muted-foreground" />
                        {/if}
                    </div>
                    <div class="flex items-center justify-between gap-1 px-2 py-1.5">
                        <div class="min-w-0">
                            <div class="truncate text-xs font-medium" title={asset.original_name}>{asset.original_name}</div>
                            <div class="text-[10px] uppercase text-muted-foreground">{asset.type} · {humanSize(asset.size_bytes)}</div>
                        </div>
                        <div class="flex shrink-0 gap-0.5">
                            <Button size="icon" variant="ghost" class="size-7" title="Insert into body" onclick={() => oninsert?.(asset)}>
                                <Plus class="size-4" />
                            </Button>
                            <Button size="icon" variant="ghost" class="size-7" title="Delete" onclick={() => remove(asset)}>
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </div>
                    </div>
                </div>
            {/each}
        </div>
    {/if}
</div>
