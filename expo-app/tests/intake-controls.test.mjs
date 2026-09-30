import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
const jsx = (type, props) => ({ type, props });
const runtime = { jsx, jsxs: jsx };
function load(path, modules) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
}
const native = Object.fromEntries(['View', 'Text', 'Pressable', 'Image', 'ActivityIndicator', 'ScrollView', 'KeyboardAvoidingView'].map(name => [name, name]));
const icons = Object.fromEntries(['Check', 'Camera', 'Plus', 'FileText', 'Trash2'].map(name => [name, name]));
const pure = {};
for (const name of ['schema', 'config', 'logic']) pure[`./${name}`] = load(`../lib/intake/${name}.ts`, pure);
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
const text = node => Array.isArray(node) ? node.map(text).join(' ') : node && typeof node === 'object' ? text(node.props?.children) : String(node ?? '');

test('field renderer supports multiple medicines, other names, condition introduction and event subfields', () => {
  const state = { answers: {} };
  const { IntakeField } = load('../components/intake/IntakeField.tsx', {
    'react/jsx-runtime': runtime, 'react-native': native, 'lucide-react-native': icons,
    './IntakeScrollView': { IntakeTextInput: 'TextInput' },
    '@/lib/intake/logic': pure['./logic'], '@/lib/intake/store': { useIntake: () => ({ state, setField: (s, f, value) => { (state.answers[s] ??= {})[f] = value; } }) },
    './AttachmentField': { AttachmentField: 'AttachmentField' }, './FieldLabel': { FieldLabel: 'FieldLabel' },
    '@/assets/images/intake-hoef-voorbeeld.png': {},
  });
  const draw = (s, id) => render(IntakeField({ sectionId: s, field: field(s, id), n: 1, noneOptions: pure['./schema'].INTAKE_NONE_OPTIONS }));
  const add = () => flatten(draw('medisch', 'medicatie-ooit-details')).find(n => n.type === 'Pressable' && text(n).includes('Medicatie toevoegen'));
  add().props.onPress();
  flatten(draw('medisch', 'medicatie-ooit-details')).find(n => n.type === 'TextInput').props.onChangeText('Ander medicijn');
  add().props.onPress();
  assert.deepEqual(state.answers.medisch['medicatie-ooit-details'], [{ naam: 'Ander medicijn' }, {}]);
  assert.equal(flatten(draw('medisch', 'medicatie-ooit-details')).filter(n => n.type === 'TextInput').length, 12);
  assert.ok(text(draw('medisch', 'sec-aandoening')).includes(field('medisch', 'sec-aandoening').hint));
  state.answers.geschiedenis = { 'medische-gebeurtenissen': [{}] };
  const tree = draw('geschiedenis', 'medische-gebeurtenissen');
  assert.ok(text(tree).includes('Wat was er aan de hand?'));
  assert.ok(text(tree).includes('Zo ja, welk onderzoek en wat kwam daaruit?'));
  flatten(tree).find(n => n.type === 'Pressable' && text(n).includes('Verbeterd, maar nog aanwezig')).props.onPress();
  assert.equal(state.answers.geschiedenis['medische-gebeurtenissen'][0].verdwenen, 'Verbeterd, maar nog aanwezig');
  state.answers.klacht = { acuut: ['Koorts / verhoging'] };
  flatten(draw('klacht', 'acuut')).find(n => n.type === 'Pressable' && text(n).includes('Geen van bovenstaande')).props.onPress();
  assert.deepEqual(state.answers.klacht.acuut, ['Geen van bovenstaande']);
});

test('blood-test upload has one limits line, allows five files, and rejects oversized files', async () => {
  const originalFetch = globalThis.fetch;
  let value = [], picks = 0, uploads = 0, size = 20;
  const state = []; let hook = 0;
  const { AttachmentField } = load('../components/intake/AttachmentField.tsx', {
    'react/jsx-runtime': runtime,
    react: { useEffect: () => {}, useState: initial => { const i = hook++; if (!(i in state)) state[i] = initial; return [state[i], next => { state[i] = next; }]; } },
    'react-native': { ...native, Platform: { OS: 'web' } }, 'lucide-react-native': icons,
    'expo-image-picker': {}, 'expo-document-picker': { getDocumentAsync: async () => { picks++; return { canceled: false, assets: [{ name: 'blood.pdf', uri: 'blob:fixture', mimeType: 'application/pdf', size, file: new File(['blood'], 'blood.pdf', { type: 'application/pdf' }) }] }; } },
    '@/db/auth': { getApiBaseUrl: () => 'https://test.invalid' }, '@/db/connector': { getOrMintToken: async () => 'fixture-token' },
    '@/lib/intake/store': { useIntake: () => ({ ensureBooking: async () => 'fixture-booking', horseId: 'fixture-horse' }) },
  });
  const draw = () => { hook = 0; return AttachmentField({ value, onChange: next => { value = next; }, section: 'klacht', field: 'bloedonderzoek', photo: false }); };
  const button = () => flatten(draw()).find(n => n.type === 'Pressable' && n.props.accessibilityRole === 'button');
  globalThis.fetch = async (url, options) => { uploads++; assert.equal(options.body.get('field'), 'bloedonderzoek'); return { ok: true, json: async () => ({ value: `attachment:${uploads}:blood.pdf` }) }; };
  try {
    assert.equal(text(draw()).match(/PDF, JPG, PNG of WebP/g).length, 1);
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
    assert.deepEqual(state.answers.voer[id], [{}]);
  }
  for (const id of ['typisch-gedrag', 'fysieke-signalen', 'stress-symptomen']) {
    const option = field('gedrag', id).options[0];
    press('gedrag', id, option);
    press('gedrag', id, 'Geen van bovenstaande');
    assert.deepEqual(state.answers.gedrag[id], ['Geen van bovenstaande']);
    press('gedrag', id, option);
    assert.deepEqual(state.answers.gedrag[id], [option]);
  }
});


test('behavior signals clear none and unknown in both directions and retain multiple real choices (OPT-97)', () => {
  const state = { answers: {} };
  const { IntakeField } = load('../components/intake/IntakeField.tsx', {
    'react/jsx-runtime': runtime, 'react-native': native, 'lucide-react-native': icons,
    './IntakeScrollView': { IntakeTextInput: 'TextInput' },
    '@/lib/intake/logic': pure['./logic'], '@/lib/intake/store': { useIntake: () => ({ state, setField: (s, f, value) => { (state.answers[s] ??= {})[f] = value; } }) },
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

test('hoof photo upload retains all eight front and side photos and allows removal (OPT-95)', async () => {
  const originalFetch = globalThis.fetch;
  let value = [], uploads = 0;
  const state = []; let hook = 0;
  const { AttachmentField } = load('../components/intake/AttachmentField.tsx', {
    'react/jsx-runtime': runtime,
    react: { useEffect: () => {}, useState: initial => { const i = hook++; if (!(i in state)) state[i] = initial; return [state[i], next => { state[i] = next; }]; } },
    'react-native': { ...native, Platform: { OS: 'web' } }, 'lucide-react-native': icons,
    'expo-image-picker': { launchImageLibraryAsync: async () => ({ canceled: false, assets: [{ fileName: 'hoef.jpg', uri: 'blob:fixture', mimeType: 'image/jpeg', fileSize: 20, file: new File(['photo'], 'hoef.jpg', { type: 'image/jpeg' }) }] }) },
    'expo-document-picker': {},
    '@/db/auth': { getApiBaseUrl: () => 'https://test.invalid' }, '@/db/connector': { getOrMintToken: async () => 'fixture-token' },
    '@/lib/intake/store': { useIntake: () => ({ ensureBooking: async () => 'fixture-booking', horseId: 'fixture-horse' }) },
  });
  const draw = () => { hook = 0; return AttachmentField({ value, onChange: next => { value = next; }, section: 'fysiek', field: 'foto-hoeven', photo: true }); };
  globalThis.fetch = async (url, options) => { uploads++; assert.equal(options.body.get('field'), 'foto-hoeven'); return { ok: true, json: async () => ({ value: `attachment:${uploads}:hoef.jpg` }) }; };
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

test('submitted intake routes cannot reopen answers, while drafts and confirmation remain available', () => {
  let loaded = false, submittedAt = null, route = 'welcome';
  const { default: Layout } = load('../app/intake/_layout.tsx', {
    'react/jsx-runtime': runtime,
    'expo-router': { Redirect: 'Redirect', Stack: 'Stack', useSegments: () => ['intake', route] },
    '@/lib/intake/store': { useIntake: () => ({ loaded, state: { submittedAt } }) },
  });
  assert.equal(Layout(), null);
  loaded = true;
  assert.equal(Layout().type, 'Stack');
  submittedAt = '2026-09-30T08:00:00Z';
  for (route of ['welcome', 'review', 'submit', '[id]']) {
    assert.deepEqual(Layout(), { type: 'Redirect', props: { href: '/intake/sent' } });
  }
  route = 'sent';
  assert.equal(Layout().type, 'Stack');
});
