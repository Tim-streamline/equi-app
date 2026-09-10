<script>
    import { BookOpen, Film, Music } from '@lucide/svelte';
    let { src = '', format = 'article', alt = '', class: className = '' } = $props();
    let failed = $state(false);
    $effect(() => { src; failed = false; });
    const Icon = $derived(format === 'video' ? Film : ['audio', 'podcast'].includes(format) ? Music : BookOpen);
</script>

<div class={`relative aspect-[4/3] overflow-hidden rounded-lg bg-[#0d5c5b] ${className}`}>
    <div class="absolute inset-0 flex items-center justify-center text-white/60"><Icon class="size-10" strokeWidth={1.5} /></div>
    {#if src && !failed}<img {src} {alt} class="absolute inset-0 h-full w-full object-cover" onerror={() => (failed = true)} />{/if}
</div>
