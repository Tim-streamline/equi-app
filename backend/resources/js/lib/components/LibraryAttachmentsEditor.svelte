<script>
    import { Button, Input } from '$lib/components/ui';
    import { FileText } from '@lucide/svelte';
    let { attachments = $bindable([]), errors = {} } = $props();
    let error = $state('');
    function add(event) {
        const files = Array.from(event.currentTarget.files ?? []);
        if (attachments.length + files.length > 20) error = 'Voeg maximaal 20 PDF-bijlagen toe.';
        else if (files.some(file => !/\.pdf$/i.test(file.name) || file.size > 20 * 1024 * 1024)) error = 'Kies PDF-bestanden van maximaal 20 MB per bestand.';
        else {
            error = '';
            attachments = [...attachments, ...files.map(file => ({ id: null, title: file.name.replace(/\.pdf$/i, ''), name: file.name, file }))];
        }
        event.currentTarget.value = '';
    }
    function replace(event, index) {
        const file = event.currentTarget.files?.[0];
        if (file) {
            if (!/\.pdf$/i.test(file.name) || file.size > 20 * 1024 * 1024) error = 'Kies een PDF-bestand van maximaal 20 MB.';
            else { error = ''; attachments[index] = { ...attachments[index], name: file.name, file }; }
        }
        event.currentTarget.value = '';
    }
    function move(index, direction) {
        const next = [...attachments];
        [next[index], next[index + direction]] = [next[index + direction], next[index]];
        attachments = next;
    }
</script>

<div class="space-y-4">
    <p class="text-sm text-muted-foreground">PDF-bijlagen gebruiken dezelfde toegang als dit item. Wijzigingen worden bij het opslaan van het item verwerkt.</p>
    <label class="block text-sm font-medium">PDF-bijlagen toevoegen
        <input class="mt-2 block w-full text-sm" type="file" accept="application/pdf,.pdf" multiple onchange={add} />
    </label>
    {#each attachments as attachment, index}
        <div class="space-y-2 rounded-lg border p-3">
            <div class="flex items-center gap-2 text-sm"><FileText class="size-4 shrink-0" /><span class="min-w-0 break-all">{attachment.name}</span></div>
            <Input aria-label={`Titel bijlage ${index + 1}`} placeholder="Titel van de bijlage" bind:value={attachment.title} />
            {#if attachment.id && !attachment.file}<a class="text-sm text-primary underline" href={`/admin/library/attachments/${attachment.id}`} target="_blank" rel="noreferrer">PDF openen</a>{/if}
            <label class="block text-xs text-muted-foreground">Bestand vervangen<input class="mt-1 block w-full text-sm" type="file" accept="application/pdf,.pdf" onchange={(event) => replace(event, index)} /></label>
            <div class="flex gap-2">
                <Button type="button" size="sm" variant="ghost" aria-label={`Bijlage ${index + 1} omhoog`} disabled={index === 0} onclick={() => move(index, -1)}>↑</Button>
                <Button type="button" size="sm" variant="ghost" aria-label={`Bijlage ${index + 1} omlaag`} disabled={index === attachments.length - 1} onclick={() => move(index, 1)}>↓</Button>
                <Button type="button" size="sm" variant="ghost" onclick={() => (attachments = attachments.filter((_, i) => i !== index))}>Verwijderen</Button>
            </div>
            {#each ['title', 'file', 'id'] as field}
                {#if errors[`attachments.${index}.${field}`]}<p role="alert" class="text-sm text-destructive">{errors[`attachments.${index}.${field}`]}</p>{/if}
            {/each}
        </div>
    {/each}
    {#if error || errors.attachments}<p role="alert" class="text-sm text-destructive">{error || errors.attachments}</p>{/if}
</div>
