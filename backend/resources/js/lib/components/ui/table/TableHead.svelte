<script>
    import { cn } from '$lib/utils.js';
    let { class: className = '', children, ...rest } = $props();
</script>

<th
    class={cn(
        'h-10 px-3 text-left align-middle text-xs font-medium uppercase tracking-wide text-muted-foreground [&:has([role=checkbox])]:pr-0',
        className,
    )}
    {...rest}
>
    {@render children?.()}
</th>
