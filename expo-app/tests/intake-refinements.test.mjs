import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
const modules = {};
for (const name of ['schema', 'config', 'logic']) {
  const source = readFileSync(name === 'schema' && process.env.INTAKE_SCHEMA_SOURCE ? process.env.INTAKE_SCHEMA_SOURCE : new URL(`../lib/intake/${name}.ts`, import.meta.url), 'utf8');
  const exports = {};
  new Function('require', 'exports', ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText)(name => modules[name], exports);
  modules[`./${name}`] = exports;
}
const { INTAKE_SCHEMA, INTAKE_NONE_OPTIONS } = modules['./schema'];
const { buildIntakeSchema } = modules['./config'];
const { visibleFields, missingRequired, isFieldAnswered, fieldFlagged, answeredCount, countFlags } = modules['./logic'];
const payload = JSON.parse(readFileSync(new URL('../../backend/database/data/intake-questionnaire.json', import.meta.url)));
const synced = buildIntakeSchema(payload.sections.map(s => ({ ...s, key: s.id, order: s.nr })), payload.sections.flatMap(s => s.fields.map((f, order) => ({ ...f, key: f.id, section_id: s.id, order, show_if: JSON.stringify(f.showIf), flag_if: JSON.stringify(f.flagIf), critical_if: JSON.stringify(f.criticalIf), repeater_sub: JSON.stringify(f.sub) }))));

test('the exported questionnaire matches the offline source including all 12 sections', () => {
  assert.deepEqual(INTAKE_SCHEMA, payload.sections);
  assert.equal(INTAKE_SCHEMA.length, 12);
  assert.deepEqual(INTAKE_NONE_OPTIONS, payload.none_options);
});
for (const [name, schema] of [['offline', INTAKE_SCHEMA], ['synced', synced]]) {
  const section = id => schema.find(s => s.id === id);
  const field = (s, id) => section(s).fields.find(f => f.id === id);
  const visible = (s, answers) => visibleFields(section(s), answers).map(f => f.id);
  test(`${name}: forage choices and analysis requirements follow the selected gate (OPT-86/88)`, () => {
    assert.deepEqual(field('voer', 'voordroog-type').options, ['Hooi', 'Voordroog', 'Kuilvoer', 'Anders, namelijk', 'Weet ik niet']);
    const answers = { 'voordroog-verleden': 'Ja, momenteel', 'voordroog-type': ['Hooi', 'Anders, namelijk'] };
    assert.ok(visible('voer', answers).includes('voordroog-type-anders'));
    answers['voordroog-verleden'] = 'Nee';
    assert.ok(!visible('voer', answers).includes('voordroog-type-anders'));
    const mandatory = ['analyse-suiker', 'analyse-eiwit', 'analyse-energie'];
    for (const choice of ['Nee', 'Weet ik niet']) {
      const a = { 'ruwvoer-geanalyseerd': choice };
      assert.ok(!visible('voer', a).some(id => mandatory.includes(id)));
      assert.ok(!missingRequired(section('voer'), a).some(f => mandatory.includes(f.id)));
    }
    const a = { 'ruwvoer-geanalyseerd': 'Ja' };
    assert.deepEqual(missingRequired(section('voer'), a).filter(f => f.id.startsWith('analyse-')).map(f => f.id), mandatory);
    for (const id of mandatory) a[id] = '8,5';
    assert.ok(!missingRequired(section('voer'), a).some(f => f.id.startsWith('analyse-') || f.id === 'hooi-analyse'));
  });
  test(`${name}: feed gates hide stale products and require at least one meaningful row for Ja (OPT-87)`, () => {
    const pairs = [['bijvoeding-nu', 'huidige-bijvoeding'], ['bijvoeding-eerder', 'bijvoeding-historie'], ['balancer-nu', 'balancer'], ['supplementen-nu', 'huidig-extra'], ['supplementen-eerder', 'historie-extra']];
    for (const [gate, detail] of pairs) {
      const gateField = field('voer', gate), detailField = field('voer', detail);
      for (const option of gateField.options.filter(o => o !== 'Ja')) {
        const a = { [gate]: option, [detail]: [{ product: 'Stale product' }] };
        assert.ok(isFieldAnswered(gateField, option));
        assert.ok(!visible('voer', a).includes(detail));
        assert.ok(!missingRequired(section('voer'), a).some(f => f.id === detail));
        const oneSection = { ...section('voer'), fields: [gateField, detailField] };
        assert.deepEqual(answeredCount(oneSection, a), { answered: 1, total: 1 });
        assert.equal(countFlags({ voer: a }, [oneSection]), 0);
      }
      for (const rows of [[], [{}]]) {
        assert.ok(missingRequired(section('voer'), { [gate]: 'Ja', [detail]: rows }).some(f => f.id === detail));
      }
      assert.ok(!missingRequired(section('voer'), { [gate]: 'Ja', [detail]: [{ product: 'Product A' }, { product: 'Product B' }] }).some(f => f.id === detail));
    }
    assert.deepEqual(field('voer', 'bijvoeding-historie').sub.map(f => f.id), ['merk', 'product', 'wanneer', 'hoelang', 'hoeveelheid', 'voerbeurten']);
  });
  test(`${name}: blood upload is optional and conditional; duplicate water question is retired (OPT-89/90)`, () => {
    const gate = field('klacht', 'bloedonderzoek-gedaan');
    assert.equal(gate.label, 'Is er in de afgelopen 3 jaar bloedonderzoek bij je paard gedaan?');
    assert.deepEqual(gate.options, ['Ja', 'Nee']);
    assert.ok(missingRequired(section('klacht'), {}).some(f => f.id === gate.id));
    assert.ok(!visible('klacht', { [gate.id]: 'Nee', bloedonderzoek: ['attachment:old'] }).includes('bloedonderzoek'));
    assert.ok(visible('klacht', { [gate.id]: 'Ja' }).includes('bloedonderzoek'));
    assert.ok(!missingRequired(section('klacht'), { [gate.id]: 'Ja' }).some(f => f.id === 'bloedonderzoek'));
    assert.ok(!field('huisvesting', 'paddock-water'));
  });
  test(`${name}: training is concrete and zero training skips irrelevant questions (OPT-91)`, () => {
    assert.ok(!field('gedrag', 'beweging-arbeid')); assert.ok(!field('gedrag', 'training-intensiteit'));
    const active = ['training-duur', 'training-stap', 'training-draf', 'training-galop', 'training-intensief', 'training-vormen', 'discipline'];
    assert.ok(!visible('gedrag', { 'training-freq': '0×' }).some(id => active.includes(id)));
    for (const option of ['1–2×', '3–4×', '5–7×']) {
      const missing = missingRequired(section('gedrag'), { 'training-freq': option }).map(f => f.id);
      for (const id of active) assert.ok(missing.includes(id), id);
    }
    for (const id of active.slice(0, 4)) { assert.equal(field('gedrag', id).type, 'number'); assert.equal(field('gedrag', id).unit, 'minuten'); }
    assert.equal(field('gedrag', 'discipline').type, 'text', 'keep existing free-text disciplines');
    assert.equal(field('gedrag', 'training-vormen').type, 'multi');
    assert.deepEqual(field('gedrag', 'training-intensief').options, ['Nooit', 'Minder dan 1× per week', '1× per week', '2× per week', '3× of vaker per week']);
    assert.ok(visible('gedrag', { 'training-freq': '0×', 'training-veranderd': 'Ja' }).includes('training-veranderd-details'));
    assert.ok(!visible('gedrag', { 'training-veranderd': 'Nee', 'training-veranderd-details': 'Stale' }).includes('training-veranderd-details'));
    const a = { 'training-freq': '0×', 'training-vormen': ['Anders, namelijk'], 'training-knelpunten': 'Stale problem' };
    assert.ok(!visible('gedrag', a).includes('training-vormen-anders'));
    assert.equal(countFlags({ gedrag: a }, [section('gedrag')]), 0);
  });
  test(`${name}: behavior none choices are last, exclusive sentinels and never flag a problem (OPT-92)`, () => {
    for (const id of ['typisch-gedrag', 'fysieke-signalen', 'stress-symptomen']) {
      const f = field('gedrag', id);
      assert.equal(f.options.at(-1), 'Geen van bovenstaande');
      assert.equal(fieldFlagged(f, ['Geen van bovenstaande']), false);
      assert.equal(fieldFlagged(f, [f.options[0]]), true);
    }
    assert.ok(!visible('gedrag', { 'typisch-gedrag': ['Geen van bovenstaande'] }).includes('typisch-gedrag-detail'));
  });
  test(`${name}: muscle loss requires a location only when selected; complaint photo is optional (OPT-93/94/95)`, () => {
    const s = section('fysiek');
    const id = 'spierverlies-locatie';
    const a = { bespiering: 'Plaatselijk spierverlies zichtbaar' };
    assert.ok(visible('fysiek', a).includes(id));
    assert.ok(missingRequired(s, a).some(f => f.id === id));
    a[id] = 'Rechts bij de achterhand';
    assert.ok(!missingRequired(s, a).some(f => f.id === id));
    for (const choice of field('fysiek', 'bespiering').options.filter(o => o !== a.bespiering)) {
      assert.ok(!visible('fysiek', { ...a, bespiering: choice }).includes(id));
    }
    assert.equal(field('fysiek', 'foto-huid').label, 'Foto van de klacht (indien van toepassing)');
    assert.equal(field('fysiek', 'foto-hoeven').label, 'Maak foto’s van alle vier de hoeven');
    assert.ok(missingRequired(s, {}).some(f => f.id === 'foto-hoeven'));
    const answers = { bespiering: 'Normaal' };
    for (const f of visibleFields(s, answers)) {
      if (f.id === 'foto-huid' || f.id === 'bespiering') continue;
      answers[f.id] = f.type === 'photo' ? ['attachment:existing:foto.jpg'] : f.type === 'multi' ? [f.options[0]] : f.options?.[0] ?? 'Ingevuld';
    }
    assert.deepEqual(missingRequired(s, answers), [], 'section can complete without a visible complaint photo');
    assert.ok(isFieldAnswered(field('fysiek', 'foto-hoeven'), Array.from({ length: 8 }, (_, i) => `attachment:${i}:hoef.jpg`)));
  });
  test(`${name}: complaint intake has the requested twelve main questions and conditional required followups (OPT-96)`, () => {
    const ids = ['hulpvraag', 'klacht-beschrijving', 'begonnen-wanneer', 'ontstaan-verandering', 'klacht-frequentie', 'klacht-ontwikkeling', 'klacht-patronen', 'klacht-invloed', 'huidige-aanpak', 'klacht-onderzocht', 'klacht-gelijktijdig', 'wens'];
    const s = section('klacht');
    assert.deepEqual(s.fields.filter(f => ids.includes(f.id)).map(f => f.id), ids);
    assert.ok(ids.every(id => missingRequired(s, {}).some(f => f.id === id)));
    assert.equal(field('klacht', 'hulpvraag').label, 'Wat is je belangrijkste klacht of hulpvraag?');
    assert.equal(field('klacht', 'begonnen-wanneer').label, 'Wanneer is dit begonnen?');
    assert.equal(field('klacht', 'wens').label, 'Wat zou je aan het einde van dit traject graag veranderd zien?');
    for (const old of ['da-behandeling', 'da-diagnose', 'onderzoek-focus', 'eerder-behandeld', 'eerder-wat', 'eerder-resultaat']) assert.ok(!field('klacht', old));
    assert.equal(field('klacht', 'subklacht').optional, true);
    assert.ok(!missingRequired(s, {}).some(f => f.id === 'klacht-ontwikkeling-toelichting'));
    for (const [gate, detail, trigger] of [
      ['ontstaan-verandering', 'ontstaan-verandering-details', 'Ja'],
      ['klacht-frequentie', 'klacht-frequentie-anders', 'Anders, namelijk'],
      ['klacht-patronen', 'klacht-patronen-details', 'Ja'],
      ['klacht-onderzocht', 'klacht-onderzoek-details', 'Ja'],
      ['klacht-gelijktijdig', 'klacht-gelijktijdig-details', 'Ja'],
    ]) {
      const pair = { ...s, fields: [field('klacht', gate), field('klacht', detail)] };
      for (const option of field('klacht', gate).options) {
        const a = { [gate]: option };
        assert.equal(visible('klacht', a).includes(detail), option === trigger);
        assert.equal(missingRequired(s, a).some(f => f.id === detail), option === trigger);
        a[detail] = 'Bewaard antwoord';
        assert.deepEqual(answeredCount(pair, a), option === trigger ? { answered: 2, total: 2 } : { answered: 1, total: 1 });
        assert.deepEqual(missingRequired(pair, a), []);
      }
    }
  });
  test(`${name}: behavior special options are ordered and only real signals flag concerns (OPT-97)`, () => {
    const f = field('gedrag', 'gedrag-signalen');
    assert.deepEqual(f.options.slice(-3), ['Geen van bovenstaande', 'Weet ik niet', 'Anders, namelijk']);
    for (const option of ['Geen van bovenstaande', 'Weet ik niet']) {
      const a = { [f.id]: [option], 'gedrag-signalen-anders': 'Stale' };
      assert.equal(fieldFlagged(f, a[f.id]), false);
      assert.ok(!visible('gedrag', a).includes('gedrag-signalen-anders'));
      assert.ok(!missingRequired(section('gedrag'), a).some(f => f.id === 'gedrag-signalen-anders'));
    }
    assert.equal(fieldFlagged(f, [f.options[0]]), true);
    assert.ok(missingRequired(section('gedrag'), { [f.id]: ['Anders, namelijk'] }).some(f => f.id === 'gedrag-signalen-anders'));
  });
  test(`${name}: horse questions retain required answers without reversing old owner answers (OPT-75/76/77)`, () => {
    assert.equal(field('paard', 'geboortedatum').hint, 'Geboortedatum niet bekend? Vul hieronder dan de (geschatte) leeftijd in.');
    const fields = section('paard').fields;
    assert.equal(fields[fields.findIndex(f => f.id === 'geboortedatum') + 1].id, 'leeftijd');
    const owner = field('paard', 'eerdere-eigenaar');
    assert.deepEqual(owner.options, ['Nee, ik heb hem/haar zelf gefokt', 'Nee, hij/zij komt rechtstreeks van de fokker', 'Ja', 'Onbekend']);
    assert.equal(owner.type, 'radio');
    assert.ok(missingRequired(section('paard'), { 'eerste-eigenaar': 'ja' }).some(f => f.id === owner.id));
    assert.ok(visible('paard', { 'eerdere-eigenaar': 'Ja' }).includes('bij-jou-hoelang'));
    assert.ok(!visible('paard', { 'eerdere-eigenaar': 'Nee, ik heb hem/haar zelf gefokt' }).includes('bij-jou-hoelang'));
    assert.equal(field('paard', 'conditie-veranderd').label, 'Is de lichaamsconditie van je paard de afgelopen jaren veranderd?');
    assert.equal(field('paard', 'conditie-veranderd').type, 'textarea');
  });
  test(`${name}: complaint questions preserve exclusion, uploads and required free text (OPT-79/80/81)`, () => {
    const acute = field('klacht', 'acuut');
    assert.equal(acute.options.at(-1), 'Geen van bovenstaande');
    assert.ok(!visible('klacht', { acuut: ['Geen van bovenstaande'] }).includes('acuut-toelichting'));
    assert.equal(fieldFlagged(acute, ['Geen van bovenstaande']), false);
    assert.equal(fieldFlagged(acute, ['Koorts / verhoging']), true);
    assert.ok(visible('klacht', { acuut: ['Koorts / verhoging'] }).includes('acuut-toelichting'));
    const blood = field('klacht', 'bloedonderzoek');
    assert.equal(blood.type, 'file'); assert.equal(blood.optional, true);
    assert.ok(!missingRequired(section('klacht'), {}).some(f => f.id === blood.id));
    assert.ok(!field('klacht', 'ervaring-holistisch'));
    for (const id of ['gedragsveranderingen', 'allergie', 'stressfactoren']) {
      assert.ok(missingRequired(section('klacht'), {}).some(f => f.id === id));
      assert.ok(field('klacht', id).hint.startsWith('Denk aan'));
      assert.ok(isFieldAnswered(field('klacht', id), 'Geen veranderingen bekend'));
    }
  });
  test(`${name}: history repeaters accept multiple events and the new outcome options (OPT-82/83)`, () => {
    assert.equal(field('geschiedenis', 'eerste-maanden').label, 'Hoe zag het leven van je paard er in de eerste maanden uit?');
    assert.equal(field('geschiedenis', 'moeder-voer-huis').label, 'Hoe werd de moeder tijdens de dracht en zoogperiode gehouden en gevoerd?');
    const events = field('geschiedenis', 'medische-gebeurtenissen');
    assert.equal(events.sub.find(f => f.id === 'symptomen').label, 'Wat was er aan de hand?');
    assert.equal(events.sub.find(f => f.id === 'reactie').label, 'Hoe reageerde je paard daarop?');
    assert.deepEqual(events.sub.find(f => f.id === 'verdwenen').options, ['Volledig verdwenen', 'Verbeterd, maar nog aanwezig', 'Komt af en toe terug', 'Nog steeds aanwezig', 'Weet ik niet']);
    assert.ok(isFieldAnswered(events, [{ symptomen: 'Blessure' }, { symptomen: 'Ziekte' }]));
  });
  test(`${name}: vaccination and shoe followups ignore stale hidden answers (OPT-84)`, () => {
    for (const choice of ['Nee', 'Weet ik niet']) {
      assert.ok(!visible('medisch', { 'vacc-gepland': choice, 'vacc-volgende': 'juni' }).includes('vacc-volgende'));
      assert.ok(!visible('medisch', { ijzers: 'nee', 'ijzers-afgelopen-twee-jaar': choice, 'ijzers-af': 'mei' }).includes('ijzers-af'));
    }
    assert.ok(missingRequired(section('medisch'), { 'vacc-gepland': 'Ja' }).some(f => f.id === 'vacc-volgende'));
    assert.ok(visible('medisch', { ijzers: 'nee', 'ijzers-afgelopen-twee-jaar': 'Ja' }).includes('ijzers-af'));
    for (const id of ['ijzers-afgelopen-twee-jaar', 'ijzers-af']) assert.ok(!visible('medisch', { ijzers: 'ja, rondom', 'ijzers-afgelopen-twee-jaar': 'Ja' }).includes(id));
    assert.ok(!field('medisch', 'mestonderzoek-uitslag-file'));
    assert.ok(field('medisch', 'sec-aandoening').hint.startsWith('Hieronder staan'));
    for (const id of ['ir-status', 'ems-status', 'kpu-status']) {
      const f = field('medisch', id);
      assert.deepEqual(f.options.slice(1), ['Ik vermoed dit', 'Voor zover ik weet niet', 'Weet ik niet']);
      assert.equal(fieldFlagged(f, f.options[0]), true);
      assert.equal(fieldFlagged(f, 'Ik vermoed dit'), true);
      assert.equal(fieldFlagged(f, 'Voor zover ik weet niet'), false);
    }
    for (const answer of ['aangetoond door dierenarts', 'Vastgesteld door dierenarts/onderzoek']) assert.ok(visible('medisch', { 'ir-status': answer }).includes('ir-attest'));
  });
  test(`${name}: medication followup needs a selected medicine and supports other names (OPT-85)`, () => {
    for (const value of [[], ['Geen']]) assert.ok(!visible('medisch', { 'medicatie-recent': 'Ja, incidenteel', 'medicatie-ooit-welke': value }).includes('medicatie-ooit-details'));
    const answers = { 'medicatie-recent': 'Ja, incidenteel', 'medicatie-ooit-welke': ['Anders, namelijk'] };
    assert.ok(visible('medisch', answers).includes('medicatie-ooit-details'));
    assert.ok(visible('medisch', answers).includes('medicatie-ooit-anders'));
    answers['medicatie-recent'] = 'Nee';
    assert.ok(!visible('medisch', answers).includes('medicatie-ooit-details'));
    assert.ok(!visible('medisch', answers).includes('medicatie-ooit-anders'));
  });
}
