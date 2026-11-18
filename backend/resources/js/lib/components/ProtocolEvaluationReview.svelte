<script>
    let { evaluation = null } = $props();
</script>

{#if evaluation}
    <section class="my-6 space-y-5 rounded-xl border bg-white p-6" aria-label="Evaluatie van de eigenaar">
        <div>
            <h2 class="text-xl font-semibold">Evaluatie van de eigenaar</h2>
            <p class="mt-1 text-sm text-muted-foreground">Ingediend op {new Date(evaluation.submitted_at).toLocaleDateString('nl-NL')}. Laatst opgeslagen op {new Date(evaluation.updated_at).toLocaleDateString('nl-NL')}.</p>
        </div>
        {#each Object.entries(evaluation.questions) as [key, question]}
            <div class="space-y-2 border-t pt-4">
                <h3 class="font-semibold">{question}</h3>
                <p class="whitespace-pre-wrap break-words text-sm leading-6">{evaluation.answers[key] || 'Niet ingevuld'}</p>
            </div>
        {/each}
    </section>
{/if}
