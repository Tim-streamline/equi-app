<script>
    import { cn } from '$lib/utils.js';
    let { class: className = '', children, ...rest } = $props();
</script>

<td class={cn('p-3 align-middle [&:has([role=checkbox])]:pr-0', className)} {...rest}>
    {@render children?.()}
</td>
