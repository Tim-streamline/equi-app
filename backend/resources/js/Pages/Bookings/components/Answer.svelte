<script>
    import { FileText, Download } from '@lucide/svelte';
    let { row, openPhoto = () => {}, printMode = false } = $props();
    const text = (value) => Array.isArray(value) ? value.join('\n') : String(value ?? '');
</script>

{#if row.empty}
    <span class="text-muted-foreground">Niet ingevuld</span>
{:else if row.type === 'multi'}
    <div class="flex flex-wrap gap-2">
        {#each row.value as option}
            <span class:attention={row.flagged_options.includes(option)} class="chip">{option}</span>
        {/each}
    </div>
{:else if row.type === 'photo' || row.type === 'file'}
    <div class="flex flex-wrap gap-3">
        {#each row.attachments as file}
            {#if file.image && row.type === 'photo'}
                <button class="photo" onclick={() => openPhoto(file)} aria-label={`Vergroot ${file.name}`}>
                    <img src={file.url} alt={file.name} loading={printMode ? 'eager' : 'lazy'} />
                    <span>{file.name}</span>
                </button>
            {:else}
                <a href={`${file.url}?download=1`} class="inline-flex items-center gap-2 text-primary underline"><FileText size={18} />{file.name}<Download size={15} /></a>
            {/if}
        {/each}
    </div>
{:else if row.type === 'repeater'}
    <div class="overflow-x-auto">
        <table><thead><tr>{#each row.sub as col}<th>{col.label}</th>{/each}</tr></thead>
            <tbody>{#each Array.isArray(row.value) ? row.value : [] as entry}<tr>{#each row.sub as col}<td>{text(entry[col.id] ?? entry[col.key]) || 'Niet ingevuld'}</td>{/each}</tr>{/each}</tbody>
        </table>
    </div>
{:else}
    <span class="whitespace-pre-wrap">{text(row.value)}{row.type === 'number' && row.unit ? ` ${row.unit}` : ''}</span>
{/if}

<style>
    .chip { padding: 4px 10px; border-radius: 999px; background: #f0f3f2; font-size: 14px; }
    .attention { background: #fff0d4; color: #825507; }
    .photo { display: flex; flex-direction: column; width: 104px; text-align: left; font-size: 12px; overflow-wrap: anywhere; cursor: zoom-in; }
    .photo img { width: 104px; height: 104px; object-fit: cover; border-radius: 12px; margin-bottom: 5px; }
    table { border-collapse: collapse; width: 100%; font-size: 14px; }
    th, td { padding: 8px; border-bottom: 1px solid #e4e9e6; text-align: left; vertical-align: top; white-space: pre-wrap; }
    th { font-weight: 600; }
</style>
