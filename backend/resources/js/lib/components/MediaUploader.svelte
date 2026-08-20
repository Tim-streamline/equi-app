<script>
    import { onMount } from 'svelte';
    import { create } from 'filepond';
    import 'filepond/dist/filepond.min.css';
    import { Button } from '$lib/components/ui';
    import { Image, Film, Music, Trash2, Plus } from '@lucide/svelte';

    let { libraryItemId = null, initial = [], oninsert } = $props();

    let media = $state([]);
    let error = $state('');
    let input;
    let pond;
    const acceptedUploadIds = new Set();

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const configuredVideoLimit = () => Number(document.querySelector('meta[name="media-max-video-bytes"]')?.content) || 2 * 1024 * 1024 * 1024;
    const configuredChunkSize = () => Number(document.querySelector('meta[name="media-chunk-size"]')?.content) || 5 * 1024 * 1024;
    const icons = { image: Image, video: Film, audio: Music };
    const megabyte = 1024 * 1024;

    function uploadRules() {
        return [
            { prefix: 'image/', extensions: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], label: 'Images', maxBytes: 10 * megabyte, maxLabel: '10 MB' },
            { prefix: 'audio/', extensions: ['mp3', 'wav', 'ogg', 'm4a', 'aac'], label: 'Audio files', maxBytes: 30 * megabyte, maxLabel: '30 MB' },
            { prefix: 'video/', extensions: ['mp4', 'webm', 'mov', 'm4v'], label: 'Videos', maxBytes: configuredVideoLimit(), maxLabel: humanSize(configuredVideoLimit()) },
        ];
    }

    function humanSize(bytes) {
        if (!bytes) return '';
        const units = ['B', 'KB', 'MB', 'GB'];
        let index = 0;
        let size = bytes;
        while (size >= 1024 && index < units.length - 1) {
            size /= 1024;
            index++;
        }
        return `${size.toFixed(size < 10 && index > 0 ? 1 : 0)} ${units[index]}`;
    }

    function validateFile(item) {
        const file = item.file;
        const extension = file.name.split('.').pop()?.toLowerCase();
        const rule = uploadRules().find((candidate) => file.type.startsWith(candidate.prefix) || candidate.extensions.includes(extension));

        if (!rule) {
            error = `${file.name} has an unsupported file type.`;
            return false;
        }
        if (file.size > rule.maxBytes) {
            error = `${file.name} is too large. ${rule.label} can be up to ${rule.maxLabel}.`;
            return false;
        }

        error = '';
        return true;
    }

    function serverError(body) {
        if (typeof body !== 'string') return 'Upload failed.';
        try {
            const data = JSON.parse(body);
            return data.errors?.file?.[0] || data.message || 'Upload failed.';
        } catch {
            return body || 'Upload failed.';
        }
    }

    async function completedUpload(file) {
        try {
            const response = await fetch(`/admin/library/media/chunks/${file.serverId}/asset`, {
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(serverError(await response.text()));

            const { asset } = await response.json();
            media = [asset, ...media];
            // FilePond normally calls `revert` when a completed chunked upload
            // is removed from its queue. Mark this transfer as accepted first
            // so clearing the queue does not delete the permanent media asset.
            acceptedUploadIds.add(String(file.serverId));
            pond.removeFile(file.id);
        } catch (exception) {
            error = exception.message;
        }
    }

    async function revertUpload(uploadId, load, reject) {
        const id = String(uploadId);

        if (acceptedUploadIds.delete(id)) {
            load();
            return;
        }

        try {
            const response = await fetch('/admin/library/media/chunks', {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrf(),
                    Accept: 'application/json',
                    'Content-Type': 'text/plain',
                },
                body: id,
            });
            if (!response.ok) throw new Error(serverError(await response.text()));
            load();
        } catch (exception) {
            error = exception.message;
            reject(exception.message);
        }
    }

    onMount(() => {
        media = [...initial];
        const headers = {
            'X-CSRF-TOKEN': csrf(),
            Accept: 'application/json',
            ...(libraryItemId ? { 'X-Library-Item-Id': libraryItemId } : {}),
        };

        pond = create(input, {
            allowMultiple: true,
            instantUpload: true,
            maxParallelUploads: 2,
            chunkUploads: true,
            chunkForce: true,
            chunkSize: configuredChunkSize(),
            chunkRetryDelays: [500, 1000, 3000],
            labelIdle: 'Drop files or <span class="filepond--label-action">browse</span>',
            beforeAddFile: validateFile,
            server: {
                process: {
                    url: '/admin/library/media/chunks',
                    method: 'POST',
                    headers,
                    onerror: serverError,
                },
                patch: {
                    url: '/admin/library/media/chunks/',
                    method: 'PATCH',
                    headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                    onerror: serverError,
                },
                revert: revertUpload,
            },
            onprocessfile: (processError, file) => {
                if (processError) {
                    error = processError.body || processError.main || 'Upload failed.';
                    return;
                }
                completedUpload(file);
            },
        });

        return () => pond?.destroy();
    });

    async function remove(asset) {
        if (!confirm(`Delete ${asset.original_name}? It will no longer load in any article that references it.`)) return;
        const response = await fetch(`/admin/library/media/${asset.id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        });
        if (response.ok) media = media.filter((item) => item.id !== asset.id);
    }
</script>

<div>
    <div class="media-pond">
        <input bind:this={input} type="file" multiple accept="image/*,video/*,audio/*" />
    </div>
    <p class="-mt-1 text-xs text-muted-foreground">Images 10 MB · audio 30 MB · video {humanSize(configuredVideoLimit())} · resumable {humanSize(configuredChunkSize())} chunks</p>

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

<style>
    :global(.media-pond .filepond--root) {
        margin-bottom: 0.5rem;
        font-family: inherit;
    }

    :global(.media-pond .filepond--panel-root) {
        background: var(--muted);
        border: 1px dashed var(--border);
    }

    :global(.media-pond .filepond--drop-label) {
        color: var(--foreground);
    }

    :global(.media-pond .filepond--label-action) {
        color: var(--primary);
        text-decoration-color: var(--primary);
    }
</style>
