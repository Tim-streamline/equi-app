<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Badge, Button, Input, Select, Textarea } from '$lib/components/ui';
    import { Dumbbell, Image as ImageIcon, Leaf, Pencil, Plus, Settings2, Trash2, X } from '@lucide/svelte';
    import { fade } from 'svelte/transition';

    let { voedingAdviezen, managementAdviezen, bewegingAdviezen, layoutOptions } = $props();

    let activeTab = $state('voeding');
    let formOpen = $state(false);
    let deleteOpen = $state(false);
    let editing = $state(null);
    let pendingDeletion = $state(null);
    let deletionProcessing = $state(false);
    let iconPreview = $state(null);
    let iconInputKey = $state(0);

    const tabs = $derived([
        {
            key: 'voeding',
            label: 'Voeding',
            entity: 'VoedingAdvies',
            description: 'Adviezen over rantsoen, ruwvoer en supplementen.',
            items: voedingAdviezen,
            layouts: layoutOptions.voeding,
        },
        {
            key: 'management',
            label: 'Management',
            entity: 'ManagementAdvies',
            description: 'Adviezen over huisvesting, routine en herstel.',
            items: managementAdviezen,
            layouts: layoutOptions.management,
        },
        {
            key: 'beweging',
            label: 'Beweging',
            entity: 'BewegingAdvies',
            description: 'Adviezen over training, opbouw en bewegingsvrijheid.',
            items: bewegingAdviezen,
            layouts: layoutOptions.beweging,
        },
    ]);
    const activeConfig = $derived(tabs.find((tab) => tab.key === activeTab) ?? tabs[0]);

    const form = useForm({ title: '', description: '', layout: 'normal', icon: null, remove_icon: false });

    function layoutLabel(layout) {
        return activeConfig.layouts.find((option) => option.value === layout)?.label ?? 'Normal';
    }

    function createAdvice() {
        editing = null;
        $form.defaults({ title: '', description: '', layout: 'normal', icon: null, remove_icon: false });
        $form.reset();
        $form.clearErrors();
        iconPreview = null;
        iconInputKey += 1;
        formOpen = true;
    }

    function editAdvice(advice) {
        editing = advice;
        $form.defaults({ title: advice.title, description: advice.description, layout: advice.layout ?? 'normal', icon: null, remove_icon: false });
        $form.reset();
        $form.clearErrors();
        iconPreview = advice.icon_url ?? null;
        iconInputKey += 1;
        formOpen = true;
    }

    function selectIcon(event) {
        const file = event.currentTarget.files?.[0] ?? null;
        $form.icon = file;
        $form.remove_icon = false;
        iconPreview = file ? URL.createObjectURL(file) : editing?.icon_url ?? null;
    }

    function removeIcon() {
        $form.icon = null;
        $form.remove_icon = true;
        iconPreview = null;
        iconInputKey += 1;
    }

    function submit(event) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => (formOpen = false),
        };

        if (editing) {
            $form
                .transform((data) => ({ ...data, _method: 'put' }))
                .post(`/admin/protocol-settings/advice/${activeTab}/${editing.id}`, { ...options, forceFormData: true });
        } else {
            $form
                .transform((data) => data)
                .post(`/admin/protocol-settings/advice/${activeTab}`, { ...options, forceFormData: true });
        }
    }

    function askToDelete(advice) {
        pendingDeletion = advice;
        deleteOpen = true;
    }

    function removeAdvice() {
        if (!pendingDeletion) return;

        deletionProcessing = true;
        router.delete(`/admin/protocol-settings/advice/${activeTab}/${pendingDeletion.id}`, {
            preserveScroll: true,
            onFinish: () => (deletionProcessing = false),
            onSuccess: () => {
                deleteOpen = false;
                pendingDeletion = null;
            },
        });
    }
</script>

<AdminLayout title="Protocol Settings">
    <PageHeader
        title="Protocol Settings"
        description="Beheer de beschikbare adviezen die in een protocol kunnen worden gebruikt."
    >
        {#snippet actions()}
            <Button onclick={createAdvice}><Plus class="size-4" /> Nieuw advies</Button>
        {/snippet}
    </PageHeader>

    <div class="overflow-hidden rounded-xl border bg-card">
        <div class="border-b bg-muted/30 px-4 pt-3 sm:px-6">
            <div class="flex gap-1 overflow-x-auto" role="tablist" aria-label="Adviescategorieën">
                {#each tabs as tab (tab.key)}
                    <button
                        type="button"
                        role="tab"
                        aria-selected={activeTab === tab.key}
                        onclick={() => (activeTab = tab.key)}
                        class="relative flex min-w-fit items-center gap-2 px-4 py-3 text-sm font-medium transition-colors"
                        class:text-primary={activeTab === tab.key}
                        class:text-muted-foreground={activeTab !== tab.key}
                    >
                        {#if tab.key === 'voeding'}
                            <Leaf class="size-4" />
                        {:else if tab.key === 'management'}
                            <Settings2 class="size-4" />
                        {:else}
                            <Dumbbell class="size-4" />
                        {/if}
                        {tab.label}
                        <Badge variant={activeTab === tab.key ? 'default' : 'muted'}>{tab.items.length}</Badge>
                        {#if activeTab === tab.key}
                            <span class="absolute inset-x-3 bottom-0 h-0.5 rounded-full bg-primary" transition:fade></span>
                        {/if}
                    </button>
                {/each}
            </div>
        </div>

        <section class="min-h-[360px]" aria-live="polite">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-5 sm:px-6">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wider text-primary">{activeConfig.entity}</div>
                    <h3 class="mt-1 text-lg font-semibold">{activeConfig.label}</h3>
                    <p class="mt-1 text-sm text-muted-foreground">{activeConfig.description}</p>
                </div>
                <Button variant="outline" size="sm" onclick={createAdvice}><Plus class="size-4" /> Toevoegen</Button>
            </div>

            {#if activeConfig.items.length}
                <div in:fade={{ duration: 140 }}>
                    {#each activeConfig.items as advice (advice.id)}
                        <article class="group flex items-start gap-4 border-b px-5 py-5 last:border-b-0 sm:px-6">
                            <div class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-primary/10 text-primary">
                                {#if advice.icon_url}
                                    <img src={advice.icon_url} alt="" class="size-full object-cover" />
                                {:else}
                                    <ImageIcon class="size-5" aria-hidden="true" />
                                {/if}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="font-semibold text-foreground">{advice.title}</h4>
                                    <Badge variant="muted">{layoutLabel(advice.layout)}</Badge>
                                </div>
                                <p class="mt-1 max-w-3xl whitespace-pre-line text-sm leading-6 text-muted-foreground">{advice.description}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1 opacity-70 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100">
                                <Button variant="ghost" size="icon" onclick={() => editAdvice(advice)} aria-label={`${advice.title} bewerken`} title="Bewerken">
                                    <Pencil class="size-4" />
                                </Button>
                                <Button variant="ghost" size="icon" onclick={() => askToDelete(advice)} aria-label={`${advice.title} verwijderen`} title="Verwijderen">
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </div>
                        </article>
                    {/each}
                </div>
            {:else}
                <div class="flex min-h-[270px] flex-col items-center justify-center px-6 text-center" in:fade={{ duration: 140 }}>
                    <div class="flex size-11 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Plus class="size-5" />
                    </div>
                    <h4 class="mt-4 font-semibold">Nog geen {activeConfig.label.toLowerCase()}adviezen</h4>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">Voeg het eerste advies toe met een herkenbare titel en duidelijke beschrijving.</p>
                    <Button class="mt-4" variant="outline" onclick={createAdvice}>Eerste advies toevoegen</Button>
                </div>
            {/if}
        </section>
    </div>

    <Modal
        bind:open={formOpen}
        title={editing ? `${activeConfig.entity} bewerken` : `${activeConfig.entity} toevoegen`}
        description={`Beschikbaar onder de tab ${activeConfig.label}.`}
    >
        <form id="protocol-advice-form" onsubmit={submit} class="space-y-4">
            <Field label="Titel" error={$form.errors.title}>
                <Input bind:value={$form.title} aria-label="Titel" autofocus placeholder="Bijvoorbeeld: Onbeperkt ruwvoer" />
            </Field>
            <Field label="Beschrijving" error={$form.errors.description}>
                <Textarea bind:value={$form.description} aria-label="Beschrijving" rows="6" placeholder="Beschrijf het advies concreet en toepasbaar." />
            </Field>
            <Field label="Layout" error={$form.errors.layout}>
                <Select bind:value={$form.layout} options={activeConfig.layouts} aria-label="Layout" />
                <p class="mt-1 text-xs text-muted-foreground">Bepaalt hoe dit advies in de mobiele app wordt weergegeven.</p>
            </Field>
            <Field label="Icoon" error={$form.errors.icon}>
                <div class="flex items-center gap-3 rounded-lg border border-dashed p-3">
                    <div class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-muted text-muted-foreground">
                        {#if iconPreview}
                            <img src={iconPreview} alt="Icoonvoorbeeld" class="size-full object-cover" />
                        {:else}
                            <ImageIcon class="size-5" aria-hidden="true" />
                        {/if}
                    </div>
                    <div class="min-w-0 flex-1">
                        {#key iconInputKey}
                            <input type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,image/*" onchange={selectIcon} class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-2 file:font-medium file:text-primary" />
                        {/key}
                        <p class="mt-1 text-xs text-muted-foreground">JPG, PNG, WebP, GIF of SVG · maximaal 2 MB.</p>
                    </div>
                    {#if iconPreview}
                        <Button type="button" variant="ghost" size="icon" onclick={removeIcon} aria-label="Icoon verwijderen"><X class="size-4" /></Button>
                    {/if}
                </div>
            </Field>
        </form>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (formOpen = false)}>Annuleren</Button>
            <Button type="submit" form="protocol-advice-form" disabled={$form.processing}>
                {editing ? 'Wijzigingen opslaan' : 'Advies toevoegen'}
            </Button>
        {/snippet}
    </Modal>

    <Modal bind:open={deleteOpen} title="Advies verwijderen" description="Deze actie kan niet ongedaan worden gemaakt." size="sm">
        <p class="text-sm text-muted-foreground">
            Weet je zeker dat je <strong class="text-foreground">{pendingDeletion?.title}</strong> wilt verwijderen?
        </p>
        {#snippet footer()}
            <Button variant="outline" onclick={() => (deleteOpen = false)}>Annuleren</Button>
            <Button variant="destructive" disabled={deletionProcessing} onclick={removeAdvice}>Verwijderen</Button>
        {/snippet}
    </Modal>
</AdminLayout>
