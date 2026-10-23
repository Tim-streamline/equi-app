import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import * as dates from '../lib/intake/dates.ts';
import * as repeater from '../lib/intake/repeater.ts';
import { saveAnswer } from '../lib/intake/persistence.ts';

function load(path, modules) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
  }).outputText;
  new Function('require', 'exports', code)(key => { assert.ok(key in modules, key); return modules[key]; }, exports);
  return exports;
}
const pure = { './dates': dates };
for (const name of ['schema', 'config', 'logic']) pure[`./${name}`] = load(`../lib/intake/${name}.ts`, pure);
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [];

// Preserve hook instances by element type and key, matching React reconciliation.
// This deliberately reuses the old row's refs if the component uses index keys.
function form(initial, dateConfig = {}) {
  let rows = initial;
  const instances = new Map();
  let hooks, cursor, active;
  const jsx = (type, props, key) => ({ type, props, key });
  const shared = {
    'react/jsx-runtime': { jsx, jsxs: jsx },
    react: { useRef: value => hooks[cursor++] ??= { current: value } },
    'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable' },
    'lucide-react-native': { Check: 'Check', Plus: 'Plus', Trash2: 'Trash2' },
    './IntakeScrollView': { IntakeTextInput: 'Input' },
    '@/lib/intake/dates': dates,
  };
  const monthYear = load('../components/intake/MonthYearField.tsx', { ...shared, './MonthSelect': { MonthSelect: 'MonthSelect' } });
  const { IntakeField } = load('../components/intake/IntakeField.tsx', {
    ...shared, './MonthYearField': monthYear, '@/lib/intake/repeater': repeater,
    '@/lib/intake/logic': pure['./logic'],
    '@/lib/intake/store': { useIntake: () => ({ state: { answers: { test: { rows } } }, setField: (_s, _f, value) => { rows = value; } }) },
    './PhotoInstructions': {}, './AttachmentField': {}, './ConfiguredPhotoField': {}, './FieldLabel': {}, './AgeField': {},
  });
  const field = { id: 'rows', type: 'repeater', label: 'Rows', sub: [
    { id: 'name', type: 'text', label: 'Name' }, { id: 'date', type: 'month_year', label: 'Date', dateConfig },
  ] };
  function render(node, path) {
    if (Array.isArray(node)) return node.map((child, index) => render(child, `${path}/${child?.key ?? index}`));
    if (!node || typeof node !== 'object') return node;
    if (typeof node.type === 'function') {
      const identity = `${path}/${node.type.name}`;
      active.add(identity);
      if (!instances.has(identity)) instances.set(identity, []);
      hooks = instances.get(identity); cursor = 0;
      return render(node.type(node.props), identity);
    }
    return { ...node, props: { ...node.props, children: render(node.props?.children, `${path}/${node.type}`) } };
  }
  const draw = () => {
    active = new Set();
    const tree = render(jsx(IntakeField, { sectionId: 'test', field, n: 1 }), 'root');
    for (const key of instances.keys()) if (!active.has(key)) instances.delete(key);
    return flatten(tree).filter(n => n.type === 'View' && n.key !== undefined && flatten(n).some(c => c.type === 'Trash2'));
  };
  return {
    rows: () => rows,
    rowKeys: () => draw().map(n => n.key),
    toggle: index => flatten(draw()[index]).find(n => n.props.accessibilityLabel === 'Weet ik niet').props.onPress(),
    remove: index => flatten(draw()[index]).find(n => n.type === 'Pressable' && flatten(n).some(c => c.type === 'Trash2')).props.onPress(),
    reorder: () => { rows = JSON.parse(JSON.stringify([...rows].reverse())); },
  };
}

const point = month => ({ version: 1, precision: 'month', month, year: 2025 });
for (const mode of ['single', 'range']) {
  const date = month => mode === 'single' ? point(month) : { version: 1, precision: 'range', start: point(month), end: point(month + 1) };
  test(`${mode}: deleting a row never restores its date into a surviving unknown row`, () => {
    const original = [{ name: 'A', date: date(7) }, { name: 'B', date: { version: 1, precision: 'unknown' } }];
    const snapshot = structuredClone(original);
    const ui = form(original, { mode });
    const keys = ui.rowKeys();
    assert.deepEqual(original, snapshot, 'viewing legacy answers does not mutate them');
    ui.toggle(0);
    assert.deepEqual(ui.rowKeys(), keys, 'the first save must not remount rows');
    ui.remove(0);
    ui.toggle(0);
    assert.equal(ui.rows()[0].date, undefined, 'never restore the deleted row\'s date');
    assert.deepEqual(ui.rowKeys(), [keys[1]]);
    assert.deepEqual(ui.rows(), [{ name: 'B', _rowId: keys[1] }]);
    assert.deepEqual(original, snapshot, 'all edits leave the historical source objects intact');
  });
  test(`${mode}: reordered and rehydrated rows restore only their own remembered date`, () => {
    const ui = form([{ name: 'A', date: date(7) }, { name: 'B', date: date(3) }], { mode });
    const keys = ui.rowKeys();
    ui.toggle(0); ui.toggle(1);
    ui.reorder();
    ui.toggle(0); ui.toggle(1);
    assert.deepEqual(ui.rows().map(row => row.date), [date(3), date(7)]);
    assert.deepEqual(ui.rowKeys(), [...keys].reverse());
    assert.deepEqual(ui.rows(), [
      { name: 'B', date: date(3), _rowId: keys[1] },
      { name: 'A', date: date(7), _rowId: keys[0] },
    ]);
  });
}

test('legacy and duplicate row IDs get unique stable identities without changing answer data', () => {
  const rows = [{ name: 'same' }, { name: 'same' }, { _rowId: 'existing', name: 'A' }, { _rowId: 'existing', name: 'B' }];
  const before = structuredClone(rows);
  const identified = repeater.withRepeaterRowIds(rows);
  assert.equal(new Set(identified.map(row => row._rowId)).size, rows.length);
  assert.deepEqual(repeater.withRepeaterRowIds(rows), identified);
  assert.deepEqual(repeater.withRepeaterRowIds(JSON.parse(JSON.stringify(identified))), identified);
  assert.equal(identified[2]._rowId, 'existing');
  assert.deepEqual(rows, before);
  assert.deepEqual(identified.map(({ _rowId, ...answer }) => answer), before.map(({ _rowId, ...answer }) => answer));
  const blank = repeater.createRepeaterRow();
  assert.equal(pure['./logic'].isFieldAnswered({ type: 'repeater', sub: [] }, [blank]), false);
  assert.notEqual(blank._rowId, repeater.createRepeaterRow()._rowId);
});

test('normal answer persistence retains IDs and all historical values on reload', async () => {
  const rows = repeater.withRepeaterRowIds([{ name: 'A', date: 'voorjaar 2024', extra: 'Keep me' }, { name: 'B', date: point(7) }]);
  let stored;
  const tx = { getOptional: async () => ({ id: 'answer' }), execute: async (_sql, params) => { stored = params[0]; } };
  await saveAnswer({ writeTransaction: callback => callback(tx) }, 'booking', 'test', 'rows', rows, () => 'unused');
  assert.deepEqual(JSON.parse(stored), rows);
  assert.deepEqual(repeater.withRepeaterRowIds(JSON.parse(stored)), rows);
});
