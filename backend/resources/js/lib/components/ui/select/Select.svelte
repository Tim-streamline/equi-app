<script>
    import { cn } from '$lib/utils.js';

    // Lightweight styled native select. `options` is an array of
    // { value, label } or plain strings.
    let {
        class: className = '',
        value = $bindable(),
        options = [],
        placeholder = undefined,
        ...rest
    } = $props();

    const normalized = $derived(
        options.map((o) => (typeof o === 'object' ? o : { value: o, label: String(o) })),
    );
</script>

<select
    bind:value
    class={cn(
        'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
        className,
    )}
    {...rest}
>
    {#if placeholder !== undefined}
        <option value="" disabled selected={value === '' || value === undefined || value === null}>
            {placeholder}
        </option>
    {/if}
    {#each normalized as opt (opt.value)}
        <option value={opt.value}>{opt.label}</option>
    {/each}
</select>
