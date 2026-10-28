import * as photoDimensions from '../lib/intake/attachments/photo-dimensions.ts';
import * as intakeDates from '../lib/intake/dates.ts';
import * as repeaterRows from '../lib/intake/repeater.ts';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
const jsx = (type, props) => ({ type, props });
const runtime = { jsx, jsxs: jsx };
function load(path, modules) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  new Function('require', 'exports', code)(name => { if (name === '@/lib/intake/repeater') return repeaterRows; if (name === './dates' || name === '@/lib/intake/dates') return intakeDates; if (name === './MonthYearField' && !(name in modules)) return { MonthYearField: 'MonthYearField' }; assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
}
const native = Object.fromEntries(['View', 'Text', 'Pressable', 'Image', 'ActivityIndicator', 'ScrollView', 'KeyboardAvoidingView'].map(name => [name, name]));
const icons = Object.fromEntries(['Check', 'Camera', 'Plus', 'FileText', 'Trash2'].map(name => [name, name]));
const pure = {};
for (const name of ['dates', 'schema', 'config', 'logic']) pure[`./${name}`] = load(`../lib/intake/${name}.ts`, pure);
const fields = pure['./schema'].INTAKE_SCHEMA;
const field = (s, f) => fields.find(x => x.id === s).fields.find(x => x.id === f);
function render(node) {
  if (Array.isArray(node)) return node.map(render);
  if (node && typeof node === 'object') {
    if (typeof node.type === 'function') return render(node.type(node.props));
    return { ...node, props: { ...node.props, children: render(node.props?.children) } };
  }
  return node;
}
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [];
const answerRows = rows => rows.map(({ _rowId, ...answer }) => answer);
const text = node => Array.isArray(node) ? node.map(text).join(' ') : node && typeof node === 'object' ? text(node.props?.children) : String(node ?? '');

for (const order of [['month', 'year'], ['year', 'month']]) {
  test(`clearing the optional medication date ${order.join(' then ')} preserves other answers and restores completion`, () => {
    const medication = field('medisch', 'medicatie-nu-details');
    const original = { naam: 'Medication', dosering: 'Daily' };
    const state = { answers: { medisch: { [medication.id]: [{ ...original, sinds: { version: 1, precision: 'month', month: 7, year: 2025 } }] } } };
    const monthYear = load('../components/intake/MonthYearField.tsx', {
      react: { useRef: () => ({ current: undefined }) }, 'react/jsx-runtime': runtime,
      'react-native': native, 'lucide-react-native': icons,
      './IntakeScrollView': { IntakeTextInput: 'DateInput' }, './MonthSelect': { MonthSelect: 'MonthSelect' },
    });
    const { IntakeField } = load('../components/intake/IntakeField.tsx', {
      'react/jsx-runtime': runtime, 'react-native': native, 'lucide-react-native': icons,
      './IntakeScrollView': { IntakeTextInput: 'TextInput' }, './MonthYearField': monthYear,
      '@/lib/intake/logic': pure['./logic'], '@/lib/intake/store': { useIntake: () => ({ state, setField: (s, f, value) => { state.answers[s][f] = value; } }) },
      './AgeField': { AgeField: 'AgeField' }, './PhotoInstructions': { PhotoInstructions: () => null },
      './ConfiguredPhotoField': { ConfiguredPhotoField: 'ConfiguredPhoto', PhotoExample: () => null },
      './AttachmentField': { AttachmentField: 'AttachmentField' }, './FieldLabel': { FieldLabel: 'FieldLabel' },
    });
    const draw = () => flatten(render(IntakeField({ sectionId: 'medisch', field: medication, n: 1 })));
    const answered = () => pure['./logic'].isFieldAnswered(medication, state.answers.medisch[medication.id]);
    const clear = part => part === 'month'
      ? draw().find(n => n.type === 'MonthSelect').props.onChange('')
      : draw().find(n => n.type === 'DateInput').props.onChangeText('');
    assert.equal(answered(), true);
    clear(order[0]);
    assert.equal(answered(), false, 'a partially entered date still prevents completion');
    clear(order[1]);
    assert.deepEqual(answerRows(state.answers.medisch[medication.id]), [original]);
    assert.equal(answered(), true);
  });
}

for (const order of [[0, 1, 2, 3], [3, 2, 1, 0]]) {
  test(`clearing a supplement period in order ${order.join(', ')} removes only that period`, () => {
    const supplement = field('voer', 'historie-extra');
    const original = { merk: 'Brand', product: 'Supplement', hoelang: 'Two months', waarom: 'Nutrition' };
    const otherRow = { product: 'Other supplement', periode: { version: 1, precision: 'unknown' } };
    const state = { answers: { voer: { [supplement.id]: [{ ...original, periode: { version: 1, precision: 'range',
      start: { version: 1, precision: 'month', month: 2, year: 2025 },
      end: { version: 1, precision: 'month', month: 7, year: 2025 } } }, otherRow] } } };
    const monthYear = load('../components/intake/MonthYearField.tsx', {
      react: { useRef: () => ({ current: undefined }) }, 'react/jsx-runtime': runtime,
      'react-native': native, 'lucide-react-native': icons,
      './IntakeScrollView': { IntakeTextInput: 'DateInput' }, './MonthSelect': { MonthSelect: 'MonthSelect' },
    });
    const { IntakeField } = load('../components/intake/IntakeField.tsx', {
      'react/jsx-runtime': runtime, 'react-native': native, 'lucide-react-native': icons,
      './IntakeScrollView': { IntakeTextInput: 'TextInput' }, './MonthYearField': monthYear,
      '@/lib/intake/logic': pure['./logic'], '@/lib/intake/store': { useIntake: () => ({ state, setField: (s, f, value) => { state.answers[s][f] = value; } }) },
      './AgeField': { AgeField: 'AgeField' }, './PhotoInstructions': { PhotoInstructions: () => null },
      './ConfiguredPhotoField': { ConfiguredPhotoField: 'ConfiguredPhoto', PhotoExample: () => null },
      './AttachmentField': { AttachmentField: 'AttachmentField' }, './FieldLabel': { FieldLabel: 'FieldLabel' },
    });
    const draw = () => flatten(render(IntakeField({ sectionId: 'voer', field: supplement, n: 1 })));
    const answered = () => pure['./logic'].isFieldAnswered(supplement, state.answers.voer[supplement.id]);
    assert.equal(answered(), true);
    for (const [step, index] of order.entries()) {
      const control = draw().filter(n => ['MonthSelect', 'DateInput'].includes(n.type))[index];
      if (control.type === 'MonthSelect') control.props.onChange(''); else control.props.onChangeText('');
      if (step < 3) assert.equal(answered(), false, 'partial periods remain invalid');
    }
    assert.deepEqual(answerRows(state.answers.voer[supplement.id]), [original, otherRow]);
    assert.equal(answered(), true);
  });
}

test('field renderer supports multiple medicines, other names, condition introduction and event subfields', () => {
  const state = { answers: {} };
  const { IntakeField } = load('../components/intake/IntakeField.tsx', {
    'react/jsx-runtime': runtime, 'react-native': native, 'lucide-react-native': icons,
    './IntakeScrollView': { IntakeTextInput: 'TextInput' },
    '@/lib/intake/logic': pure['./logic'], '@/lib/intake/store': { useIntake: () => ({ state, setField: (s, f, value) => { (state.answers[s] ??= {})[f] = value; } }) },
    './AgeField': { AgeField: 'AgeField' },
    './PhotoInstructions': {PhotoInstructions: () => null},
    './ConfiguredPhotoField': { ConfiguredPhotoField: 'ConfiguredPhoto', PhotoExample: () => null },
    './AttachmentField': { AttachmentField: 'AttachmentField' }, './FieldLabel': { FieldLabel: 'FieldLabel' },
    '@/assets/images/intake-hoef-voorbeeld.png': {},
  });
  const draw = (s, id) => render(IntakeField({ sectionId: s, field: field(s, id), n: 1, noneOptions: pure['./schema'].INTAKE_NONE_OPTIONS }));
  const add = () => flatten(draw('medisch', 'medicatie-ooit-details')).find(n => n.type === 'Pressable' && text(n).includes('Medicatie toevoegen'));
  add().props.onPress();
  flatten(draw('medisch', 'medicatie-ooit-details')).find(n => n.type === 'TextInput').props.onChangeText('Ander medicijn');
  add().props.onPress();
  assert.deepEqual(answerRows(state.answers.medisch['medicatie-ooit-details']), [{ naam: 'Ander medicijn' }, {}]);
  assert.equal(flatten(draw('medisch', 'medicatie-ooit-details')).filter(n => n.type === 'TextInput').length, 10);
  assert.ok(text(draw('medisch', 'sec-aandoening')).includes(field('medisch', 'sec-aandoening').hint));
  state.answers.geschiedenis = { 'medische-gebeurtenissen': [{}] };
  const tree = draw('geschiedenis', 'medische-gebeurtenissen');
  assert.ok(text(tree).includes('Wat was er aan de hand?'));
  assert.ok(text(tree).includes('Zo ja, welk onderzoek en wat kwam daaruit?'));
  flatten(tree).find(n => n.type === 'Pressable' && text(n).includes('Verbeterd, maar nog aanwezig')).props.onPress();
  assert.equal(state.answers.geschiedenis['medische-gebeurtenissen'][0].verdwenen, 'Verbeterd, maar nog aanwezig');
  const events = field('geschiedenis', 'medische-gebeurtenissen');
  const na = () => flatten(draw('geschiedenis', events.id)).find(n => n.props.accessibilityRole === 'checkbox');
  const history = fields.find(s => s.id === 'geschiedenis');
  const beforeNa = pure['./logic'].answeredCount(history, state.answers.geschiedenis, state.answers);
  assert.equal(pure['./logic'].countFlags(state.answers), 1);
  assert.equal(na().props.accessibilityState.checked, false);
  na().props.onPress();
  assert.equal(na().props.accessibilityState.checked, true);
  assert.equal(pure['./logic'].isFieldAnswered(events, state.answers.geschiedenis[events.id], state.answers, 'geschiedenis'), true);
  assert.equal(pure['./logic'].answeredCount(history, state.answers.geschiedenis, state.answers).answered, beforeNa.answered);
  assert.equal(pure['./logic'].countFlags(state.answers), 0);
  assert.ok(!text(draw('geschiedenis', events.id)).includes('Eerste toevoegen'));
  assert.deepEqual(answerRows(state.answers.geschiedenis[events.id]), [{ verdwenen: 'Verbeterd, maar nog aanwezig' }]);
  na().props.onPress();
  assert.equal(na().props.accessibilityState.checked, false);
  assert.ok(text(draw('geschiedenis', events.id)).includes('Nog één toevoegen'));
  state.answers.geschiedenis[events.id] = [];
  assert.equal(pure['./logic'].isFieldAnswered(events, [], state.answers, 'geschiedenis'), false);
  assert.equal(pure['./logic'].answeredCount(history, state.answers.geschiedenis, state.answers).answered, beforeNa.answered - 1);
  na().props.onPress();
  assert.equal(pure['./logic'].answeredCount(history, state.answers.geschiedenis, state.answers).answered, beforeNa.answered);
  assert.ok(!pure['./logic'].missingRequired(history, state.answers.geschiedenis, state.answers).some(f => f.id === events.id));
  const kpuDate = flatten(draw('medisch', 'kpu-onderzoek-datum')).find(n => n.type === 'MonthYearField');
  assert.equal(kpuDate.props.config.mode, 'single');
  kpuDate.props.onChange({ version: 1, precision: 'month', month: 4, year: 2025 });
  assert.deepEqual(state.answers.medisch['kpu-onderzoek-datum'], { version: 1, precision: 'month', month: 4, year: 2025 });
  state.answers.klacht = { acuut: ['Koorts / verhoging'] };
  flatten(draw('klacht', 'acuut')).find(n => n.type === 'Pressable' && text(n).includes('Geen van bovenstaande')).props.onPress();
  assert.deepEqual(state.answers.klacht.acuut, ['Geen van bovenstaande']);
});

test('offline blood-test selection shows size restrictions only on validation failure and allows five files', async () => {
  const originalFetch = globalThis.fetch;
  let value = [], picks = 0, uploads = 0, size = 20;
  const state = []; let hook = 0;
  const { AttachmentField } = load('../components/intake/AttachmentField.tsx', {
    'react/jsx-runtime': runtime,
    react: { useRef: value => ({ current: value }), useEffect: () => {}, useState: initial => { const i = hook++; if (!(i in state)) state[i] = initial; return [state[i], next => { state[i] = next; }]; } },
    'react-native': { ...native, Platform: { OS: 'web' } }, 'lucide-react-native': icons,
    './AttachmentPhoto': { Photo: 'Photo' },
    '@/lib/intake/attachments/photo-dimensions': photoDimensions,
    '@/lib/intake/attachments/image-picker': { releasePickedImages() {}, }, 'expo-document-picker': { getDocumentAsync: async () => { picks++; return { canceled: false, assets: [{ name: 'blood.pdf', uri: 'blob:fixture', mimeType: 'application/pdf', size, file: new File(['blood'], 'blood.pdf', { type: 'application/pdf' }) }] }; } },
    '@/db/auth': { getApiBaseUrl: () => 'https://test.invalid' }, '@/db/connector': { getOrMintToken: async () => 'fixture-token' },
    '@/db/provider': { useDb: () => ({ currentUserId: 'owner' }) },
    '@/lib/account-session': { accountSession: { revision: 0 } },
    '@/lib/intake/attachments/storage': { saveAttachment: async (record, asset) => { uploads++; assert.ok(asset.file); assert.equal(record.userId, 'owner'); } },
    '@/lib/intake/attachments/events': {},
    '@/lib/refresh-safety': { registerRefreshProtection: () => () => {}, refreshProtectionChanged: () => {} },
    '@/lib/intake/store': { useIntake: () => ({ state: {submittedAt: null}, submitting: false, ensureBooking: async () => 'fixture-booking', horseId: 'fixture-horse' }) },
  });
  const draw = () => { hook = 0; return AttachmentField({ value, onChange: next => { value = next; }, section: 'klacht', field: 'bloedonderzoek', photo: false }); };
  const button = () => flatten(draw()).find(n => n.type === 'Pressable' && n.props.accessibilityRole === 'button');
  globalThis.fetch = async () => { assert.fail('Adding an attachment must not use the network'); };
  try {
    assert.doesNotMatch(text(draw()), /JPG|PNG|WebP|15 MB/);
    assert.ok(text(draw()).includes('Maximaal 5 bestanden'));
    assert.ok(text(button()).includes('Bloeduitslag toevoegen'));
    for (let i = 0; i < 5; i++) { button().props.onPress(); await new Promise(resolve => setImmediate(resolve)); }
    assert.equal(value.length, 5); assert.equal(uploads, 5);
    assert.equal(button().props.disabled, true);
    button().props.onPress(); await new Promise(resolve => setImmediate(resolve)); assert.equal(picks, 5);
    flatten(draw()).find(n => n.props.accessibilityLabel === 'Bijlage verwijderen').props.onPress();
    assert.equal(button().props.disabled, false);
    size = 16 * 1024 * 1024;
    button().props.onPress(); await new Promise(resolve => setImmediate(resolve));
    assert.equal(uploads, 5); assert.ok(text(draw()).includes('Maximaal 15 MB per bestand'));
  } finally { globalThis.fetch = originalFetch; }
});

test('supplement repeaters support add/remove and every new none option clears other choices', () => {
  const state = { answers: {} };
  const { IntakeField } = load('../components/intake/IntakeField.tsx', {
    'react/jsx-runtime': runtime, 'react-native': native, 'lucide-react-native': icons,
    './IntakeScrollView': { IntakeTextInput: 'TextInput' },
    '@/lib/intake/logic': pure['./logic'], '@/lib/intake/store': { useIntake: () => ({ state, setField: (s, f, value) => { (state.answers[s] ??= {})[f] = value; } }) },
    './AgeField': { AgeField: 'AgeField' },
    './PhotoInstructions': {PhotoInstructions: () => null},
    './ConfiguredPhotoField': { ConfiguredPhotoField: 'ConfiguredPhoto', PhotoExample: () => null },
    './AttachmentField': { AttachmentField: 'AttachmentField' }, './FieldLabel': { FieldLabel: 'FieldLabel' },
    '@/assets/images/intake-hoef-voorbeeld.png': {},
  });
  const draw = (s, id) => render(IntakeField({ sectionId: s, field: field(s, id), n: 1, noneOptions: pure['./schema'].INTAKE_NONE_OPTIONS }));
  const press = (s, id, label) => {
    const button = flatten(draw(s, id)).find(n => n.type === 'Pressable' && flatten(n).some(child => child.type === 'Text' && text(child).trim() === label));
    assert.ok(button, label); button.props.onPress();
  };
  for (const id of ['huidig-extra', 'historie-extra']) {
    press('voer', id, 'Supplement toevoegen');
    flatten(draw('voer', id)).find(n => n.type === 'TextInput').props.onChangeText('Product A');
    press('voer', id, 'Nog één toevoegen');
    assert.equal(state.answers.voer[id].length, 2);
    assert.equal(state.answers.voer[id][0].merk, 'Product A');
    flatten(draw('voer', id)).find(n => n.type === 'Pressable' && flatten(n).some(c => c.type === 'Trash2')).props.onPress();
    assert.deepEqual(answerRows(state.answers.voer[id]), [{}]);
  }
  for (const id of ['typisch-gedrag', 'fysieke-signalen', 'stress-symptomen']) {
    const option = field('gedrag', id).options[0];
    press('gedrag', id, option);
    press('gedrag', id, 'Geen van bovenstaande');
    assert.deepEqual(state.answers.gedrag[id], ['Geen van bovenstaande']);
    press('gedrag', id, option);
    assert.deepEqual(state.answers.gedrag[id], [option]);
  }
  press('medisch', 'ems-diagnose-basis', 'Beoordeling door een dierenarts');
  press('medisch', 'ems-diagnose-basis', 'Weet ik niet');
  assert.deepEqual(state.answers.medisch['ems-diagnose-basis'], ['Weet ik niet']);
  press('medisch', 'ems-diagnose-basis', 'Eerdere of terugkerende hoefbevangenheid');
  assert.deepEqual(state.answers.medisch['ems-diagnose-basis'], ['Eerdere of terugkerende hoefbevangenheid']);
});


test('behavior signals clear none and unknown in both directions and retain multiple real choices (OPT-97)', () => {
  const state = { answers: {} };
  const { IntakeField } = load('../components/intake/IntakeField.tsx', {
    'react/jsx-runtime': runtime, 'react-native': native, 'lucide-react-native': icons,
    './IntakeScrollView': { IntakeTextInput: 'TextInput' },
    '@/lib/intake/logic': pure['./logic'], '@/lib/intake/store': { useIntake: () => ({ state, setField: (s, f, value) => { (state.answers[s] ??= {})[f] = value; } }) },
    './AgeField': { AgeField: 'AgeField' },
    './PhotoInstructions': {PhotoInstructions: () => null},
    './ConfiguredPhotoField': { ConfiguredPhotoField: 'ConfiguredPhoto', PhotoExample: () => null },
    './AttachmentField': { AttachmentField: 'AttachmentField' }, './FieldLabel': { FieldLabel: 'FieldLabel' },
    '@/assets/images/intake-hoef-voorbeeld.png': {},
  });
  const f = field('gedrag', 'gedrag-signalen');
  const draw = () => render(IntakeField({ sectionId: 'gedrag', field: f, n: 1, noneOptions: pure['./schema'].INTAKE_NONE_OPTIONS }));
  const press = label => flatten(draw()).find(n => n.type === 'Pressable' && flatten(n).some(c => c.type === 'Text' && text(c).trim() === label)).props.onPress();
  for (const sentinel of ['Geen van bovenstaande', 'Weet ik niet']) {
    press(f.options[0]); press(f.options[1]);
    assert.deepEqual(state.answers.gedrag[f.id], f.options.slice(0, 2));
    press(sentinel); assert.deepEqual(state.answers.gedrag[f.id], [sentinel]);
    press('Anders, namelijk'); assert.deepEqual(state.answers.gedrag[f.id], ['Anders, namelijk']);
    press('Anders, namelijk'); assert.deepEqual(state.answers.gedrag[f.id], []);
  }
  press('Weet ik niet'); press('Geen van bovenstaande');
  assert.deepEqual(state.answers.gedrag[f.id], ['Geen van bovenstaande']);
  press('Weet ik niet'); assert.deepEqual(state.answers.gedrag[f.id], ['Weet ik niet']);
});

test('offline hoof photo selection retains all eight front and side photos and allows removal (OPT-95)', async () => {
  const originalFetch = globalThis.fetch;
  let value = [], uploads = 0;
  const state = []; let hook = 0;
  const { AttachmentField } = load('../components/intake/AttachmentField.tsx', {
    'react/jsx-runtime': runtime,
    react: { useRef: value => ({ current: value }), useEffect: () => {}, useState: initial => { const i = hook++; if (!(i in state)) state[i] = initial; return [state[i], next => { state[i] = next; }]; } },
    'react-native': { ...native, Platform: { OS: 'web' } }, 'lucide-react-native': icons,
    './AttachmentPhoto': { Photo: 'Photo' },
    '@/lib/intake/attachments/photo-dimensions': photoDimensions,
    '@/lib/intake/attachments/image-picker': { releasePickedImages() {},  launchImageLibraryAsync: async () => ({ canceled: false, assets: [{ fileName: 'hoef.jpg', uri: 'blob:fixture', mimeType: 'image/jpeg', fileSize: 20, file: new File(['photo'], 'hoef.jpg', { type: 'image/jpeg' }) }] }) },
    'expo-document-picker': {},
    '@/db/auth': { getApiBaseUrl: () => 'https://test.invalid' }, '@/db/connector': { getOrMintToken: async () => 'fixture-token' },
    '@/db/provider': { useDb: () => ({ currentUserId: 'owner' }) },
    '@/lib/account-session': { accountSession: { revision: 0 } },
    '@/lib/intake/attachments/storage': { saveAttachment: async (record, asset) => { uploads++; assert.ok(asset.file); assert.equal(record.userId, 'owner'); } },
    '@/lib/intake/attachments/events': {},
    '@/lib/refresh-safety': { registerRefreshProtection: () => () => {}, refreshProtectionChanged: () => {} },
    '@/lib/intake/store': { useIntake: () => ({ state: {submittedAt: null}, submitting: false, ensureBooking: async () => 'fixture-booking', horseId: 'fixture-horse' }) },
  });
  const draw = () => { hook = 0; return AttachmentField({ value, onChange: next => { value = next; }, section: 'fysiek', field: 'foto-hoeven', photo: true }); };
  globalThis.fetch = async () => { assert.fail('Adding an attachment must not use the network'); };
  try {
    for (let i = 0; i < 8; i++) {
      const button = flatten(draw()).find(n => n.type === 'Pressable' && n.props.accessibilityRole === 'button');
      assert.equal(button.props.disabled, false); button.props.onPress();
      await new Promise(resolve => setImmediate(resolve));
    }
    assert.equal(value.length, 8); assert.equal(new Set(value).size, 8);
    flatten(draw()).find(n => n.props.accessibilityLabel === 'Bijlage verwijderen').props.onPress();
    assert.equal(value.length, 7);
  } finally { globalThis.fetch = originalFetch; }
});

test('submitted intakes allow read-only viewing but cannot revisit submission or onboarding', () => {
  let loaded = false, submittedAt = null, route = 'welcome', horseId = 'horse', horsesLoading = true, hasSynced = false, horses = [];
  const { default: Layout } = load('../app/intake/_layout.tsx', {
    'react/jsx-runtime': runtime,
    react: { useEffect: () => {}, useRef: value => ({ current: value }), useState: initial => [initial === undefined ? { horseId: 'horse', value: { intakeAvailable: true } } : initial, () => {}] },
    'react-native': { Text: 'Text', View: 'View' },
    '@/components/ui/Button': { Button: 'Button' },
    '@/hooks/useHorseDashboard': { dashboardRequest: async () => ({}) },
    '@powersync/react': { useStatus: () => ({ hasSynced }), useQuery: () => ({ data: horses, isLoading: horsesLoading }) },
    '@/db/hooks': { useCurrentUserId: () => 'user' },
    'expo-router': { Redirect: 'Redirect', Stack: 'Stack', useSegments: () => ['intake', route] },
    '@/lib/intake/store': { useIntake: () => ({ loaded, horseId, bookingId: 'purchased-intake', state: { submittedAt } }) },
  });
  assert.equal(Layout(), null);
  loaded = true;
  assert.equal(Layout().type, 'Stack');
  submittedAt = '2026-09-30T08:00:00Z';
  for (route of ['welcome', 'submit']) {
    assert.deepEqual(Layout(), { type: 'Redirect', props: { href: '/intake/sent' } });
  }
  for (route of ['sent', 'overview', '[id]']) assert.equal(Layout().type, 'Stack');
  horseId = '';
  assert.equal(Layout().type, 'View', 'horse data still loading must not redirect');
  horsesLoading = false; hasSynced = true; horses = [{ id: 'horse' }];
  assert.equal(Layout().type, 'View', 'wait for the selected horse hook to catch up');
  horses = [];
  assert.equal(Layout().props.href, '/onboarding/add-horse');
});

test('a hydrated existing draft opens offline without requesting Plus access', async () => {
  let bookingId = 'existing-booking', loaded = true, requests = 0;
  let effects = [];
  const { default: Layout } = load('../app/intake/_layout.tsx', {
    'react/jsx-runtime': runtime,
    react: { useEffect: effect => effects.push(effect), useRef: value => ({ current: value }), useState: initial => [initial, () => {}] },
    'react-native': native,
    '@/components/ui/Button': { Button: 'Button' },
    '@/hooks/useHorseDashboard': { dashboardRequest: async () => { requests++; throw new Error('offline'); } },
    '@powersync/react': { useStatus: () => ({ hasSynced: true }), useQuery: () => ({ data: [{ id: 'horse' }], isLoading: false }) },
    '@/db/hooks': { useCurrentUserId: () => 'user' },
    'expo-router': { Redirect: 'Redirect', Stack: 'Stack', useSegments: () => ['intake', 'welcome'] },
    '@/lib/intake/store': { useIntake: () => ({ loaded, bookingId, horseId: 'horse', state: { submittedAt: null } }) },
  });
  assert.equal(Layout().type, 'Stack');
  effects.splice(0).forEach(effect => effect());
  assert.equal(requests, 0);
  loaded = false; bookingId = null;
  assert.equal(Layout(), null);
  effects.splice(0).forEach(effect => effect());
  assert.equal(requests, 0, 'wait for local hydration before considering creation');
  loaded = true;
  assert.equal(Layout(), null);
  effects.splice(0).forEach(effect => effect());
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(requests, 1, 'new intake still checks online access');
});
