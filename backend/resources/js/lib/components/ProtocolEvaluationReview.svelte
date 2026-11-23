<script>
    let { evaluation = null } = $props();
    // The same calendar day as the PDF export, wherever the browser is.
    const day = time => new Date(time).toLocaleDateString('nl-NL', { timeZone: 'Europe/Amsterdam' });
</script>

{#if evaluation}
    <section class="my-6 space-y-5 rounded-xl border bg-white p-6" aria-label="Evaluatie van de eigenaar">
        <div>
            <h2 class="text-xl font-semibold">Evaluatie van de eigenaar</h2>
            <p class="mt-1 text-sm text-muted-foreground">Ingediend op {day(evaluation.submitted_at)}. Laatst opgeslagen op {day(evaluation.updated_at)}.</p>
        </div>
        {#each Object.entries(evaluation.questions) as [key, question]}
            <div class="space-y-2 border-t pt-4">
                <h3 class="font-semibold">{question}</h3>
                <p class="whitespace-pre-wrap break-words text-sm leading-6">{evaluation.answers[key] || 'Niet ingevuld'}</p>
            </div>
        {/each}
    </section>
{/if}
