<script>
    import { cn } from '$lib/utils.js';
    let { class: className = '', orientation = 'horizontal', ...rest } = $props();
</script>

<div
    role="separator"
    class={cn('shrink-0 bg-border', orientation === 'horizontal' ? 'h-px w-full' : 'h-full w-px', className)}
    {...rest}
></div>
