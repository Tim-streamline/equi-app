<script>
    import { cn } from '$lib/utils.js';
    let { class: className = '', children, ...rest } = $props();
</script>

<div class="relative w-full overflow-x-auto">
    <table class={cn('w-full caption-bottom text-sm', className)} {...rest}>
        {@render children?.()}
    </table>
</div>
