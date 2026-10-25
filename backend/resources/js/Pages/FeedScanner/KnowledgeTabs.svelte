<script>
    import { Link } from '@inertiajs/svelte';
    let { active = 'ingredients' } = $props();
</script>
<nav aria-label="Kennisbank" class="mb-6 flex gap-2 border-b pb-3">
    {#each [['ingredients', 'Ingrediënten'], ['nutrition', 'Voedingskundige kennis']] as [key, label]}
        <Link href={`/admin/feed-scanner/knowledge?tab=${key}`} aria-current={active === key ? 'page' : undefined} class={`rounded-lg px-4 py-2 text-sm font-medium ${active === key ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'}`}>{label}</Link>
    {/each}
</nav>
