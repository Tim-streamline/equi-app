<script>
    import { onDestroy } from 'svelte';
    import { BookOpen, Clock3, Play, X } from '@lucide/svelte';

    let {
        open = false,
        onclose,
        title = '',
        format = 'article',
        description = '',
        body = '',
        heroImageUrl = '',
        durationLabel = '',
        authorName = '',
        publishedAt = '',
        categories = [],
    } = $props();

    let dialog;
    let VideoJsPlayer = $state(null);

    const formatLabels = {
        article: 'Article',
        video: 'Video',
        course: 'Course',
        program: 'Program',
    };

    function safeUrl(value) {
        const url = String(value ?? '').trim();
        if (!url) return '';
        if (url.startsWith('/')) return url;
        try {
            const parsed = new URL(url, typeof window === 'undefined' ? 'http://localhost' : window.location.origin);
            return ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : '';
        } catch {
            return '';
        }
    }

    function inlineTokens(text) {
        const source = String(text ?? '')
            .replace(/<\/?(?:b|strong)>/gi, '**')
            .replace(/<\/?(?:i|em)>/gi, '*')
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<[^>]+>/g, '');
        const tokens = [];
        const pattern = /(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\([^)]+\)|\*[^*]+\*)/g;
        let cursor = 0;

        for (const match of source.matchAll(pattern)) {
            if (match.index > cursor) tokens.push({ type: 'text', text: source.slice(cursor, match.index) });
            const value = match[0];
            if (value.startsWith('**')) tokens.push({ type: 'strong', text: value.slice(2, -2) });
            else if (value.startsWith('`')) tokens.push({ type: 'code', text: value.slice(1, -1) });
            else if (value.startsWith('[')) {
                const parts = value.match(/^\[([^\]]+)]\(([^)]+)\)$/);
                const url = safeUrl(parts?.[2]);
                tokens.push(url ? { type: 'link', text: parts[1], url } : { type: 'text', text: parts?.[1] ?? value });
            } else tokens.push({ type: 'em', text: value.slice(1, -1) });
            cursor = match.index + value.length;
        }

        if (cursor < source.length) tokens.push({ type: 'text', text: source.slice(cursor) });
        return tokens;
    }

    function bodyBlocks(value) {
        const source = String(value ?? '').trim();
        if (!source) return [];

        const parts = [];
        const mediaPattern = /<(video|audio)\b[^>]*\bsrc=["']([^"']+)["'][^>]*>[\s\S]*?<\/\1>/gi;
        let cursor = 0;

        for (const match of source.matchAll(mediaPattern)) {
            if (match.index > cursor) parts.push({ type: 'text', value: source.slice(cursor, match.index) });
            parts.push({ type: match[1].toLowerCase(), url: safeUrl(match[2]) });
            cursor = match.index + match[0].length;
        }
        if (cursor < source.length) parts.push({ type: 'text', value: source.slice(cursor) });

        const chunks = parts.flatMap((part) => {
            if (part.type !== 'text') return [part];
            return part.value
                .split(/\n\s*\n/)
                .map((chunk) => ({ type: 'text', value: chunk.trim() }))
                .filter((chunk) => chunk.value);
        });

        return chunks.map((part, index) => {
            if (part.type === 'video' || part.type === 'audio') return { id: index, type: part.type, url: part.url };

            const chunk = part.value;
            const image = chunk.match(/^!\[([^\]]*)]\(([^)]+)\)$/s);
            if (image) return { id: index, type: 'image', alt: image[1], url: safeUrl(image[2]) };

            const heading = chunk.match(/^(#{1,3})\s+(.+)$/s);
            if (heading) return { id: index, type: 'heading', level: heading[1].length, text: heading[2].trim() };

            const lines = chunk.split('\n');
            if (lines.every((line) => /^[-*]\s+/.test(line.trim()))) {
                return { id: index, type: 'list', items: lines.map((line) => line.trim().replace(/^[-*]\s+/, '')) };
            }

            if (lines.every((line) => /^>\s?/.test(line.trim()))) {
                return { id: index, type: 'quote', text: lines.map((line) => line.trim().replace(/^>\s?/, '')).join(' ') };
            }

            return { id: index, type: 'paragraph', text: lines.join(' ').trim() };
        });
    }

    let blocks = $derived(bodyBlocks(body));
    let safeHero = $derived(safeUrl(heroImageUrl));
    let hasVideo = $derived(blocks.some((block) => block.type === 'video' && block.url));

    $effect(() => {
        if (!dialog) return;
        if (open && !dialog.open) {
            dialog.showModal();
            document.body.style.overflow = 'hidden';
        } else if (!open && dialog.open) {
            dialog.close();
        }
    });

    $effect(() => {
        if (open && hasVideo && !VideoJsPlayer) {
            import('./VideoJsPlayer.svelte').then((module) => (VideoJsPlayer = module.default));
        }
    });

    onDestroy(() => {
        if (typeof document !== 'undefined') document.body.style.overflow = '';
    });

    function closePreview() {
        if (dialog?.open) dialog.close();
    }

    function handleClose() {
        document.body.style.overflow = '';
        onclose?.();
    }

    function handleBackdrop(event) {
        if (event.target === dialog) closePreview();
    }
</script>

{#snippet inline(text)}
    {#each inlineTokens(text) as token}
        {#if token.type === 'strong'}<strong>{token.text}</strong>
        {:else if token.type === 'em'}<em>{token.text}</em>
        {:else if token.type === 'code'}<code>{token.text}</code>
        {:else if token.type === 'link'}<a href={token.url} target="_blank" rel="noreferrer">{token.text}</a>
        {:else}{token.text}{/if}
    {/each}
{/snippet}

<dialog
    bind:this={dialog}
    aria-labelledby="library-preview-title"
    class="m-auto max-h-[92vh] w-[min(94vw,920px)] overflow-hidden rounded-2xl border bg-background p-0 text-foreground shadow-2xl backdrop:bg-slate-950/55"
    onclose={handleClose}
    onclick={handleBackdrop}
>
    <div class="flex max-h-[92vh] flex-col">
        <header class="flex shrink-0 items-center justify-between border-b bg-background px-5 py-3.5">
            <div>
                <h2 id="library-preview-title" class="text-sm font-semibold">Library item preview</h2>
                <p class="text-xs text-muted-foreground">Includes your unsaved changes</p>
            </div>
            <button
                type="button"
                class="inline-flex size-9 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label="Close preview"
                onclick={closePreview}
            >
                <X class="size-4" />
            </button>
        </header>

        <div class="overflow-y-auto bg-muted/45 p-4 sm:p-7">
            <article class="mx-auto max-w-2xl overflow-hidden rounded-[1.25rem] bg-background shadow-sm ring-1 ring-black/5">
                <div class="relative flex min-h-56 items-center justify-center overflow-hidden bg-[#0d5c5b] sm:min-h-64">
                    {#if safeHero}
                        <img src={safeHero} alt="" class="absolute inset-0 size-full object-cover" />
                        <div class="absolute inset-0 bg-black/20"></div>
                    {:else}
                        <div class="absolute -right-16 -top-24 size-72 rounded-full border border-white/10"></div>
                        <div class="absolute -bottom-24 -left-14 size-64 rounded-full border border-white/10"></div>
                        {#if format === 'video'}
                            <div class="flex size-16 items-center justify-center rounded-full bg-white text-[#0d5c5b] shadow-lg"><Play class="ml-1 size-7" /></div>
                        {:else}
                            <BookOpen class="size-14 text-white/85" strokeWidth={1.5} />
                        {/if}
                    {/if}

                    <div class="absolute left-4 top-4 rounded-full bg-white/92 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-[#0d5c5b] shadow-sm">
                        {formatLabels[format] ?? format}
                    </div>
                </div>

                <div class="px-6 py-7 sm:px-10 sm:py-9">
                    <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-muted-foreground">
                        <span>{publishedAt ? 'Published' : 'Draft preview'}</span>
                        {#if durationLabel}
                            <span class="text-border">•</span>
                            <span class="inline-flex items-center gap-1.5"><Clock3 class="size-3.5" /> {durationLabel}</span>
                        {/if}
                        {#if authorName}
                            <span class="text-border">•</span>
                            <span>By {authorName}</span>
                        {/if}
                    </div>

                    <h1 class="text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">{title || 'Untitled library item'}</h1>
                    {#if description}<p class="mt-4 text-base leading-7 text-muted-foreground sm:text-lg">{description}</p>{/if}

                    {#if categories.length}
                        <div class="mt-5 flex flex-wrap gap-2">
                            {#each categories as category (category.id)}
                                <span class="rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary">{category.label}</span>
                            {/each}
                        </div>
                    {/if}

                    <div class="mt-8 border-t pt-7 text-[15px] leading-7 text-foreground/90 sm:text-base">
                        {#if blocks.length}
                            {#each blocks as block (block.id)}
                                {#if block.type === 'image' && block.url}
                                    <figure class="my-6 overflow-hidden rounded-xl bg-muted"><img src={block.url} alt={block.alt} class="max-h-[32rem] w-full object-cover" /></figure>
                                {:else if block.type === 'video' && block.url}
                                    {#if VideoJsPlayer}
                                        <VideoJsPlayer src={block.url} class="my-6 aspect-video w-full rounded-xl" />
                                    {:else}
                                        <div class="my-6 aspect-video w-full animate-pulse rounded-xl bg-black/80"></div>
                                    {/if}
                                {:else if block.type === 'audio' && block.url}
                                    <audio src={block.url} controls class="my-6 w-full"></audio>
                                {:else if block.type === 'heading'}
                                    <h2 class="mb-2 mt-8 text-xl font-semibold tracking-tight sm:text-2xl">{@render inline(block.text)}</h2>
                                {:else if block.type === 'list'}
                                    <ul class="my-4 list-disc space-y-1.5 pl-5">
                                        {#each block.items as item}<li>{@render inline(item)}</li>{/each}
                                    </ul>
                                {:else if block.type === 'quote'}
                                    <blockquote class="my-5 border-l-2 border-primary pl-4 italic text-muted-foreground">{@render inline(block.text)}</blockquote>
                                {:else if block.type === 'paragraph'}
                                    <p class="my-4">{@render inline(block.text)}</p>
                                {/if}
                            {/each}
                        {:else}
                            <p class="rounded-xl bg-muted px-4 py-5 text-sm text-muted-foreground">Add body content to see it here.</p>
                        {/if}
                    </div>
                </div>
            </article>
        </div>
    </div>
</dialog>

<style>
    dialog[open] {
        animation: preview-in 160ms ease-out;
    }

    dialog[open]::backdrop {
        animation: backdrop-in 160ms ease-out;
    }

    article :global(a) {
        color: var(--primary);
        text-decoration: underline;
        text-underline-offset: 3px;
    }

    article :global(code) {
        border-radius: 0.3rem;
        background: var(--muted);
        padding: 0.12rem 0.35rem;
        font-size: 0.88em;
    }

    @keyframes preview-in {
        from { opacity: 0; transform: translateY(10px) scale(0.985); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    @keyframes backdrop-in {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @media (prefers-reduced-motion: reduce) {
        dialog[open], dialog[open]::backdrop { animation: none; }
    }
</style>
