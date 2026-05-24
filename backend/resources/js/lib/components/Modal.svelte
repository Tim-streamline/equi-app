<script>
    import { X } from '@lucide/svelte';
    import { cn } from '$lib/utils.js';

    let { open = $bindable(false), title = '', description = undefined, size = 'md', children, footer = undefined } = $props();

    const sizes = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };

    function onkeydown(e) {
        if (e.key === 'Escape') open = false;
    }
</script>

<svelte:window {onkeydown} />

{#if open}
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 py-10">
        <div
            class="fixed inset-0"
            onclick={() => (open = false)}
            role="presentation"
        ></div>
        <div class={cn('relative z-10 w-full rounded-xl border bg-card shadow-lg', sizes[size])}>
            <div class="flex items-start justify-between border-b px-6 py-4">
                <div>
                    <h3 class="font-semibold">{title}</h3>
                    {#if description}<p class="mt-0.5 text-sm text-muted-foreground">{description}</p>{/if}
                </div>
                <button onclick={() => (open = false)} class="text-muted-foreground hover:text-foreground" aria-label="Close">
                    <X class="size-5" />
                </button>
            </div>
            <div class="max-h-[70vh] overflow-y-auto px-6 py-5">
                {@render children?.()}
            </div>
            {#if footer}
                <div class="flex items-center justify-end gap-2 border-t px-6 py-4">
                    {@render footer()}
                </div>
            {/if}
        </div>
    </div>
{/if}
