<script>
    import { Card, CardContent } from '$lib/components/ui';
    import { cn } from '$lib/utils.js';

    let { label, value, hint = undefined, icon = undefined, accent = 'primary', class: className = '' } = $props();

    const Icon = $derived(icon);

    const accents = {
        primary: 'text-primary bg-primary/10',
        success: 'text-success bg-success/10',
        warning: 'text-warning bg-warning/15',
        destructive: 'text-destructive bg-destructive/10',
        muted: 'text-muted-foreground bg-muted',
    };
</script>

<Card class={className}>
    <CardContent class="flex items-center gap-4 p-5">
        {#if Icon}
            <div class={cn('flex size-11 items-center justify-center rounded-lg', accents[accent])}>
                <Icon class="size-5" />
            </div>
        {/if}
        <div class="min-w-0">
            <div class="text-2xl font-semibold tracking-tight">{value}</div>
            <div class="text-sm text-muted-foreground">{label}</div>
            {#if hint}<div class="text-xs text-muted-foreground/80">{hint}</div>{/if}
        </div>
    </CardContent>
</Card>
