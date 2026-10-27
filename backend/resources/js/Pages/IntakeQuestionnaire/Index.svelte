<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Modal from '$lib/components/Modal.svelte';
    import Field from '$lib/components/Field.svelte';
    import IntakeConditionEditor from '$lib/components/IntakeConditionEditor.svelte';
    import { router, useForm } from '@inertiajs/svelte';
    import { Card, CardContent, Button, Input, Textarea, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { Plus, Pencil, Archive, Save, Settings2 } from '@lucide/svelte';

    let { questionnaire, fieldTypes } = $props();
    let selectedSectionId = $state(questionnaire.sections.find((section) => section.active)?.id ?? questionnaire.sections[0]?.id ?? '');
    let sectionOpen = $state(false);
    let fieldOpen = $state(false);
    let editingSection = $state(null);
    let editingField = $state(null);

    const activeSections = $derived(questionnaire.sections.filter((section) => section.active));
    const selectedSection = $derived(questionnaire.sections.find((section) => section.id === selectedSectionId) ?? activeSections[0] ?? questionnaire.sections[0]);

    const questionnaireForm = useForm({
        name: questionnaire.name,
        disclaimer_short: questionnaire.disclaimer_short ?? '',
        disclaimer_long: questionnaire.disclaimer_long ?? '',
        none_options_json: JSON.stringify(questionnaire.none_options ?? [], null, 2),
    });
    const sectionForm = useForm({
        questionnaire_id: questionnaire.id,
        key: '',
        title: '',
        intro: '', presentation: { intro_style: 'plain', offline_notice: '' },
        subtitle: '',
        icon: 'sparkles',
        minutes: 2,
        order: questionnaire.sections.length,
        active: true,
    });
    const fieldForm = useForm(emptyField());
    const conditionQuestions = $derived([
        ...(selectedSection?.fields ?? []).filter(f => f.active && ['radio', 'multi', 'number', 'text'].includes(f.type)).map(f => ({ key: f.key, label: `Deze sectie · ${f.label}`, options: f.options, type: f.type })),
        ...questionnaire.sections.flatMap(section => section.fields.filter(f => f.active && ['radio', 'multi', 'number', 'text'].includes(f.type)).map(f => ({ key: `${f.answer_section_key ?? section.key}.${f.key}`, label: `${section.title} · ${f.label}`, options: f.options, type: f.type }))),
    ]);
    function parsedConditions() { try { return JSON.parse($fieldForm.show_if_json || 'null'); } catch { return null; } }
    function parsedOptions() { try { const options = JSON.parse($fieldForm.options_json || '[]'); return Array.isArray(options) ? options : []; } catch { return []; } }

    function emptyField() {
        return {
            section_id: selectedSection?.id ?? '',
            key: '',
            label: '',
            type: 'text',
            hint: '',
            required: false,
            optional: false,
            unit: '',
            step: '',
            tall: false,
            lines: '',
            placeholder: '',
            order: selectedSection?.fields?.length ?? 0,
            active: true,
            options_json: '',
            answer_aliases_json: '',
            submission_blocks: [],
            show_if_json: '',
            flag_if_json: '',
            critical_if_json: '',
            protocol_if_json: '',
            signal_config_json: '',
            link_json: '',
            repeater_sub_json: '',
            photo_config_json: '',
            age_config_json: '',
            date_config_json: '',
        };
    }

    function json(value) {
        return value === null || value === undefined ? '' : JSON.stringify(value, null, 2);
    }

    function createSection() {
        editingSection = null;
        $sectionForm.defaults({ questionnaire_id: questionnaire.id, key: '', title: '', intro: '', presentation: { intro_style: 'plain', offline_notice: '' }, subtitle: '', icon: 'sparkles', minutes: 2, order: questionnaire.sections.length, active: true });
        $sectionForm.reset();
        $sectionForm.clearErrors();
        sectionOpen = true;
    }

    function editSection(section) {
        editingSection = section;
        $sectionForm.defaults({
            questionnaire_id: questionnaire.id,
            key: section.key,
            title: section.title,
            intro: section.intro ?? '',
            presentation: { intro_style: section.presentation?.intro_style ?? 'plain', offline_notice: section.presentation?.offline_notice ?? '' },
            subtitle: section.subtitle ?? '',
            icon: section.icon ?? '',
            minutes: section.minutes,
            order: section.order,
            active: section.active,
        });
        $sectionForm.reset();
        $sectionForm.clearErrors();
        sectionOpen = true;
    }

    function submitSection(event) {
        event.preventDefault();
        const options = { onSuccess: () => (sectionOpen = false) };
        editingSection
            ? $sectionForm.put(`/admin/intake-questionnaire/sections/${editingSection.id}`, options)
            : $sectionForm.post('/admin/intake-questionnaire/sections', options);
    }

    function createField() {
        editingField = null;
        $fieldForm.defaults(emptyField());
        $fieldForm.reset();
        $fieldForm.clearErrors();
        fieldOpen = true;
    }

    function editField(field) {
        editingField = field;
        $fieldForm.defaults({
            section_id: field.section_id,
            key: field.key,
            label: field.label,
            type: field.type,
            hint: field.hint ?? '',
            required: field.required,
            optional: field.optional,
            unit: field.unit ?? '',
            step: field.step ?? '',
            tall: field.tall,
            lines: field.lines ?? '',
            placeholder: field.placeholder ?? '',
            order: field.order,
            active: field.active,
            options_json: json(field.options),
            answer_aliases_json: json(field.answer_aliases),
            submission_blocks: structuredClone(field.submission_blocks ?? []),
            show_if_json: json(field.show_if),
            flag_if_json: json(field.flag_if),
            critical_if_json: json(field.critical_if),
            protocol_if_json: json(field.protocol_if),
            signal_config_json: json(field.signal_config),
            link_json: json(field.link),
            repeater_sub_json: json(field.repeater_sub),
            photo_config_json: json(field.photo_config),
            age_config_json: json(field.age_config),
            date_config_json: json(field.date_config),
        });
        $fieldForm.reset();
        $fieldForm.clearErrors();
        fieldOpen = true;
    }

    function submitField(event) {
        event.preventDefault();
        try {
            const options = JSON.parse($fieldForm.options_json || 'null');
            if (Array.isArray(options) && options.every(option => typeof option === 'string')) $fieldForm.options_json = JSON.stringify(options.map(option => option.trim()).filter(Boolean));
        } catch { /* Keep invalid advanced JSON so the server can show its validation error. */ }
        const options = { onSuccess: () => (fieldOpen = false) };
        editingField
            ? $fieldForm.put(`/admin/intake-questionnaire/fields/${editingField.id}`, options)
            : $fieldForm.post('/admin/intake-questionnaire/fields', options);
    }

    function archiveSection(section) {
        if (confirm(`Archiveer sectie “${section.title}”? Bestaande antwoorden blijven bewaard.`)) {
            router.delete(`/admin/intake-questionnaire/sections/${section.id}`);
        }
    }

    function archiveField(field) {
        if (confirm(`Archiveer vraag “${field.label}”? Bestaande antwoorden blijven bewaard.`)) {
            router.delete(`/admin/intake-questionnaire/fields/${field.id}`);
        }
    }
</script>

<AdminLayout title="Intakevragen">
    <PageHeader title="Intakevragen" description="Beheer de volledige protocol-intake, inclusief vertakkingen, signaleringen en veldgedrag.">
        {#snippet actions()}
            <Button variant="outline" onclick={createSection}><Plus class="size-4" /> Sectie</Button>
            <Button onclick={createField} disabled={!selectedSection}><Plus class="size-4" /> Vraag</Button>
        {/snippet}
    </PageHeader>

    <Card class="mb-5">
        <CardContent class="p-5">
            <form onsubmit={(event) => { event.preventDefault(); $questionnaireForm.put(`/admin/intake-questionnaire/${questionnaire.id}`); }} class="space-y-4">
                <div class="flex items-center gap-2 font-semibold"><Settings2 class="size-4 text-primary" /> Algemene intaketeksten</div>
                <Field label="Naam" error={$questionnaireForm.errors.name}><Input bind:value={$questionnaireForm.name} /></Field>
                <div class="grid gap-4 lg:grid-cols-2">
                    <Field label="Korte disclaimer" error={$questionnaireForm.errors.disclaimer_short}><Textarea rows="5" bind:value={$questionnaireForm.disclaimer_short} /></Field>
                    <Field label="Lange disclaimer" error={$questionnaireForm.errors.disclaimer_long}><Textarea rows="5" bind:value={$questionnaireForm.disclaimer_long} /></Field>
                </div>
                <Field label="Exclusieve ‘geen / niet van toepassing’-antwoorden (JSON-array)" hint="Bij een meerkeuzevraag wist zo'n antwoord alle andere selecties en telt het niet als inhoudelijk signaal." error={$questionnaireForm.errors.none_options_json}>
                    <Textarea rows="5" bind:value={$questionnaireForm.none_options_json} placeholder={'["geen", "niet van toepassing"]'} />
                </Field>
                <div class="flex justify-end"><Button type="submit" disabled={$questionnaireForm.processing}><Save class="size-4" /> Opslaan</Button></div>
            </form>
        </CardContent>
    </Card>

    <Card>
        <CardContent class="p-0">
            <div class="flex flex-wrap items-end gap-3 border-b p-4">
                <div class="min-w-64 flex-1">
                    <Field label="Sectie">
                        <Select bind:value={selectedSectionId} options={questionnaire.sections.map((section) => ({ value: section.id, label: `${section.order + 1}. ${section.title}${section.active ? '' : ' (gearchiveerd)'}` }))} />
                    </Field>
                </div>
                {#if selectedSection}
                    {#if !selectedSection.active}<Badge variant="muted">gearchiveerde sectie</Badge>{/if}
                    <Badge variant="muted">{selectedSection.fields.filter((field) => field.active).length} actieve velden</Badge>
                    <Button variant="outline" onclick={() => editSection(selectedSection)}><Pencil class="size-4" /> Sectie wijzigen</Button>
                    <Button variant="ghost" onclick={() => archiveSection(selectedSection)}><Archive class="size-4 text-destructive" /> Archiveren</Button>
                {/if}
            </div>

            {#if selectedSection}
                <div class="border-b bg-muted/30 px-5 py-3 text-sm text-muted-foreground">
                    <span class="font-mono text-xs">{selectedSection.key}</span>
                    {#if selectedSection.intro}<span> · {selectedSection.intro}</span>{/if}
                </div>
                <Table>
                    <TableHeader><TableRow><TableHead class="w-16">Volgorde</TableHead><TableHead>Vraag</TableHead><TableHead>Type</TableHead><TableHead>Logica</TableHead><TableHead class="w-24"></TableHead></TableRow></TableHeader>
                    <TableBody>
                        {#each selectedSection.fields as field (field.id)}
                            <TableRow class={!field.active ? 'opacity-45' : ''}>
                                <TableCell>{field.order + 1}</TableCell>
                                <TableCell>
                                    <div class="font-medium">{field.label}</div>
                                    <div class="font-mono text-[11px] text-muted-foreground">{field.key}</div>
                                </TableCell>
                                <TableCell><Badge variant="muted">{field.type}</Badge></TableCell>
                                <TableCell>
                                    <div class="flex flex-wrap gap-1">
                                        {#if field.optional}<Badge variant="outline">optioneel</Badge>{/if}
                                        {#if field.show_if}<Badge>conditioneel</Badge>{/if}
                                        {#if field.flag_if}<Badge variant="warning">signaal</Badge>{/if}
                                        {#if field.critical_if}<Badge variant="destructive">kritiek</Badge>{/if}
                                        {#if !field.active}<Badge variant="muted">gearchiveerd</Badge>{/if}
                                    </div>
                                </TableCell>
                                <TableCell class="text-right">
                                    <Button size="sm" variant="ghost" onclick={() => editField(field)}><Pencil class="size-4" /></Button>
                                    {#if field.active}<Button size="sm" variant="ghost" onclick={() => archiveField(field)}><Archive class="size-4 text-destructive" /></Button>{/if}
                                </TableCell>
                            </TableRow>
                        {/each}
                    </TableBody>
                </Table>
            {/if}
        </CardContent>
    </Card>

    <Modal bind:open={sectionOpen} title={editingSection ? 'Sectie wijzigen' : 'Sectie toevoegen'} size="lg">
        <form id="intake-section-form" onsubmit={submitSection} class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <Field label="Technische sleutel" hint="Stabiele koppeling met opgeslagen antwoorden." error={$sectionForm.errors.key}><Input bind:value={$sectionForm.key} /></Field>
                <Field label="Volgorde" error={$sectionForm.errors.order}><Input type="number" min="0" bind:value={$sectionForm.order} /></Field>
            </div>
            <Field label="Titel" error={$sectionForm.errors.title}><Input bind:value={$sectionForm.title} /></Field>
            <Field label="Introductie-opmaak"><select class="rounded border p-2" bind:value={$sectionForm.presentation.intro_style}><option value="plain">Gewone tekst</option><option value="card">Donkergroene informatiekaart</option></select></Field>
            <Field label="Offline-melding bovenaan" error={$sectionForm.errors['presentation.offline_notice']}><Textarea rows="2" bind:value={$sectionForm.presentation.offline_notice} /></Field>
            <Field label="Introductie" error={$sectionForm.errors.intro}><Textarea rows="3" bind:value={$sectionForm.intro} /></Field>
            <div class="grid gap-4 md:grid-cols-3">
                <Field label="Ondertitel" error={$sectionForm.errors.subtitle}><Input bind:value={$sectionForm.subtitle} /></Field>
                <Field label="Icooncode" error={$sectionForm.errors.icon}><Input bind:value={$sectionForm.icon} /></Field>
                <Field label="Minuten" error={$sectionForm.errors.minutes}><Input type="number" min="0" bind:value={$sectionForm.minutes} /></Field>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" bind:checked={$sectionForm.active} /> Actief in de app</label>
        </form>
        {#snippet footer()}<Button variant="outline" onclick={() => (sectionOpen = false)}>Annuleren</Button><Button type="submit" form="intake-section-form" disabled={$sectionForm.processing}>Opslaan</Button>{/snippet}
    </Modal>

    <Modal bind:open={fieldOpen} title={editingField ? 'Vraag wijzigen' : 'Vraag toevoegen'} description="Alle JSON-velden ondersteunen dezelfde logica als het oorspronkelijke app-schema." size="xl">
        <form id="intake-field-form" onsubmit={submitField} class="space-y-5">
            <Field label="Sectie" error={$fieldForm.errors.section_id} hint="Bij verplaatsen blijven bestaande antwoorden en uploads gekoppeld aan deze vraag."><Select bind:value={$fieldForm.section_id} options={questionnaire.sections.filter(section => section.active).map(section => ({ value: section.id, label: section.title }))} /></Field>
            <div class="grid gap-4 md:grid-cols-3">
                <Field label="Technische sleutel" hint="Niet wijzigen nadat antwoorden zijn opgeslagen." error={$fieldForm.errors.key}><Input bind:value={$fieldForm.key} /></Field>
                <Field label="Type" error={$fieldForm.errors.type}><Select bind:value={$fieldForm.type} options={fieldTypes.map(type => ({ value: type, label: type === 'month_year' ? 'Maand en jaar / periode' : type }))} /></Field>
                <Field label="Volgorde" error={$fieldForm.errors.order}><Input type="number" min="0" bind:value={$fieldForm.order} /></Field>
            </div>
            <Field label="Vraag of tussenkop" error={$fieldForm.errors.label}><Textarea rows="2" bind:value={$fieldForm.label} /></Field>
            <Field label="Helptekst" error={$fieldForm.errors.hint}><Textarea rows="2" bind:value={$fieldForm.hint} /></Field>
            <div class="grid gap-4 md:grid-cols-4">
                <Field label="Eenheid" error={$fieldForm.errors.unit}><Input bind:value={$fieldForm.unit} /></Field>
                <Field label="Stapgrootte" error={$fieldForm.errors.step}><Input type="number" step="any" bind:value={$fieldForm.step} /></Field>
                <Field label="Aantal regels" error={$fieldForm.errors.lines}><Input type="number" min="1" bind:value={$fieldForm.lines} /></Field>
                <Field label="Placeholder" error={$fieldForm.errors.placeholder}><Input bind:value={$fieldForm.placeholder} /></Field>
            </div>
            <div class="flex flex-wrap gap-5 rounded-lg border p-3 text-sm">
                <label class="flex items-center gap-2"><input type="checkbox" bind:checked={$fieldForm.optional} /> Optioneel</label>
                <label class="flex items-center gap-2"><input type="checkbox" bind:checked={$fieldForm.tall} /> Hoog tekstveld</label>
                <label class="flex items-center gap-2"><input type="checkbox" bind:checked={$fieldForm.active} /> Actief</label>
            </div>

            <section class="space-y-3 rounded-xl border p-4">
                <h3 class="font-semibold">Antwoorden die versturen blokkeren</h3>
                <p class="text-sm">Bij deze antwoorden verschijnt de melding boven de verstuurknop. De klant kan pas versturen zodra geen blokkerend antwoord meer is geselecteerd.</p>
                {#each $fieldForm.submission_blocks as rule, i}
                    <div class="space-y-2 rounded-lg border p-3">
                        <Field label="Blokkerend antwoord"><Input bind:value={rule.answer} placeholder="Exacte antwoordoptie" /></Field>
                        <Field label="Melding voor de klant"><Textarea rows="3" bind:value={rule.message} /></Field>
                        <Button type="button" variant="outline" onclick={() => $fieldForm.submission_blocks = $fieldForm.submission_blocks.filter((_, index) => index !== i)}>Blokkade verwijderen</Button>
                    </div>
                {/each}
                {#if $fieldForm.errors.submission_blocks}<p class="text-red-700">{$fieldForm.errors.submission_blocks}</p>{/if}
                <Button type="button" variant="outline" onclick={() => $fieldForm.submission_blocks = [...$fieldForm.submission_blocks, { answer: '', message: '' }]}>Blokkerend antwoord toevoegen</Button>
            </section>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2"><Field label="Maand/jaar-instellingen (JSON)" error={$fieldForm.errors.date_config_json} hint="Type month_year: mode single of range, allow_future, allow_year_only, min_year. allow_unknown: false verbergt ‘Weet ik niet’. month_label, year_label, unknown_label en ongoing_label bepalen de teksten. Ook via dateConfig bij herhaal-subvelden."><Textarea rows="3" bind:value={$fieldForm.date_config_json} placeholder={'{"mode":"single","allow_future":false,"allow_year_only":true}'} /></Field></div>
                <div class="md:col-span-2"><Field label="Leeftijd-instellingen (JSON)" error={$fieldForm.errors.age_config_json} hint="Type number: date_field is de opslagsleutel van de geboortedatum. date_label, toggle_label en estimate_label bepalen de teksten. calculated_hint is een verouderd optioneel veld en wordt niet meer getoond."><Textarea rows="6" bind:value={$fieldForm.age_config_json} /></Field></div>
                <div class="md:col-span-2"><Field label="Foto-instellingen (JSON)" error={$fieldForm.errors.photo_config_json} hint="instruction_label en instructions: bewerkbaar instructiekopje en fotoinstructies; de Helptekst bevat de uitleg waarom. max: maximum aantal foto’s; year_required: jaartal verplicht per foto; allow_na: Niet van toepassing toestaan; slots: afzonderlijke aanzichten met id en label; optioneel answer_field bewaart een bestaande antwoordsleutel binnen dezelfde antwoordsectie. caption: toelichting per foto met label en placeholder (verwijder caption om te verbergen). example_url en example_caption: voorbeeldafbeelding; example_after: tekst onder het voorbeeld. na_key verbindt een gedeelde keuze; existing_only toont alleen eerder opgeslagen foto’s."><Textarea rows="8" bind:value={$fieldForm.photo_config_json} placeholder={'{"max":10,"year_required":true,"allow_na":false}'} /></Field></div>
                <Field label="Antwoordopties" hint="Eén antwoord per regel. Ook voor een getalvraag met ‘Weet ik niet’ of een herhaalvraag met ‘Niet van toepassing’." error={$fieldForm.errors.options_json}>
                    <Textarea rows="6" value={parsedOptions().join('\n')} oninput={event => $fieldForm.options_json = JSON.stringify(event.currentTarget.value.split('\n'))} placeholder={'Ja\nNee\nWeet ik niet'} />
                    <details class="mt-2 text-sm"><summary class="cursor-pointer">Geavanceerd: antwoordopties als JSON</summary><Textarea rows="4" bind:value={$fieldForm.options_json} /></details>
                </Field>
                <Field label="Oude antwoordwaarden (JSON)" hint="Koppel een oude opgeslagen waarde aan een huidige optie. Opgeslagen antwoorden blijven ongewijzigd." error={$fieldForm.errors.answer_aliases_json}><Textarea rows="4" bind:value={$fieldForm.answer_aliases_json} /></Field>
                <Field label="Eigenaarssignalen voorbereiden (JSON)" hint="Alleen open tekst. condition is de context; signals bevat bewerkbare signaalcodes en omschrijvingen. De bron blijft altijd eigenaar-gerapporteerd en niet bevestigd. Dit activeert geen AI-aanroep of protocoladvies." error={$fieldForm.errors.signal_config_json}><Textarea rows="5" bind:value={$fieldForm.signal_config_json} placeholder={'{"condition":"IR","signals":{"vetkam":"Een vetkam genoemd door de eigenaar"}}'} /></Field>
                <div class="md:col-span-2"><Field label="Wanneer verschijnt deze vraag?" error={$fieldForm.errors.show_if_json} hint="Combineer voorwaarden met ‘alle’ of ‘minstens één’. Gebruik een omgekeerde voorwaarde om een antwoord uit te sluiten.">
                    <IntakeConditionEditor value={parsedConditions()} questions={conditionQuestions} onchange={rule => $fieldForm.show_if_json = rule ? JSON.stringify(rule, null, 2) : ''} />
                    <details class="mt-3 text-sm"><summary class="cursor-pointer">Geavanceerd: zichtbaarheidsregel bewerken</summary><Textarea rows="5" bind:value={$fieldForm.show_if_json} placeholder={'{"paard.geslacht":"merrie"}'} /></details>
                </Field></div>
                <Field label="Signaleringsregel flagIf (JSON)" error={$fieldForm.errors.flag_if_json}><Textarea rows="4" bind:value={$fieldForm.flag_if_json} placeholder='["Ja"] of "non-empty"' /></Field>
                <Field label="Kritieke regel criticalIf (JSON)" error={$fieldForm.errors.critical_if_json}><Textarea rows="4" bind:value={$fieldForm.critical_if_json} placeholder='["Ja"]' /></Field>
                <Field label="Protocollogica protocolIf (JSON-object)" error={$fieldForm.errors.protocol_if_json}><Textarea rows="4" bind:value={$fieldForm.protocol_if_json} /></Field>
                <Field label="Link (JSON-object)" error={$fieldForm.errors.link_json}><Textarea rows="4" bind:value={$fieldForm.link_json} placeholder={'{"text":"Lees meer","url":"https://..."}'} /></Field>
                <div class="md:col-span-2"><Field label="Repeater-subvelden (JSON-array)" error={$fieldForm.errors.repeater_sub_json}><Textarea rows="7" bind:value={$fieldForm.repeater_sub_json} placeholder={'[{"id":"naam","label":"Naam","type":"text"}]'} /></Field></div>
            </div>
        </form>
        {#snippet footer()}<Button variant="outline" onclick={() => (fieldOpen = false)}>Annuleren</Button><Button type="submit" form="intake-field-form" disabled={$fieldForm.processing}>Opslaan</Button>{/snippet}
    </Modal>
</AdminLayout>
