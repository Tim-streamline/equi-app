<script>
    let { title, description = undefined, actions = undefined } = $props();
</script>

<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h2 class="text-xl font-semibold tracking-tight">{title}</h2>
        {#if description}<p class="mt-1 text-sm text-muted-foreground">{description}</p>{/if}
    </div>
    {#if actions}
        <div class="flex items-center gap-2">{@render actions()}</div>
    {/if}
</div>
