<script>
    import { Label } from '$lib/components/ui';

    let { label = undefined, error = undefined, hint = undefined, children } = $props();
</script>

<div class="space-y-1.5">
    {#if label}<Label>{label}</Label>{/if}
    {@render children?.()}
    {#if hint && !error}<p class="text-xs text-muted-foreground">{hint}</p>{/if}
    {#if error}<p class="text-xs text-destructive">{error}</p>{/if}
</div>
