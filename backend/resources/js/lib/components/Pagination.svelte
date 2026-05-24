<script>
    import { Link } from '@inertiajs/svelte';
    import { cn } from '$lib/utils.js';

    // Expects a Laravel paginator object: { links: [{url,label,active}], from, to, total }
    let { paginator } = $props();
</script>

{#if paginator?.links?.length > 3}
    <div class="flex flex-wrap items-center justify-between gap-3 pt-4">
        <div class="text-sm text-muted-foreground">
            {paginator.from ?? 0}–{paginator.to ?? 0} of {paginator.total}
        </div>
        <div class="flex flex-wrap items-center gap-1">
            {#each paginator.links as link (link.label)}
                {#if link.url}
                    <Link
                        href={link.url}
                        preserveScroll
                        class={cn(
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-sm',
                            link.active ? 'border-primary bg-primary text-primary-foreground' : 'bg-background hover:bg-accent',
                        )}
                    >
                        {@html link.label}
                    </Link>
                {:else}
                    <span class="inline-flex h-8 min-w-8 items-center justify-center px-2 text-sm text-muted-foreground/50">
                        {@html link.label}
                    </span>
                {/if}
            {/each}
        </div>
    </div>
{/if}
