<script module>
    import { tv } from 'tailwind-variants';

    export const badgeVariants = tv({
        base: 'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium transition-colors whitespace-nowrap',
        variants: {
            variant: {
                default: 'border-transparent bg-primary text-primary-foreground',
                secondary: 'border-transparent bg-secondary text-secondary-foreground',
                outline: 'text-foreground',
                success: 'border-transparent bg-success/15 text-success',
                warning: 'border-transparent bg-warning/20 text-warning',
                destructive: 'border-transparent bg-destructive/15 text-destructive',
                muted: 'border-transparent bg-muted text-muted-foreground',
            },
        },
        defaultVariants: { variant: 'default' },
    });
</script>

<script>
    import { cn } from '$lib/utils.js';
    let { variant = 'default', class: className = '', children, ...rest } = $props();
</script>

<span class={cn(badgeVariants({ variant }), className)} {...rest}>
    {@render children?.()}
</span>
