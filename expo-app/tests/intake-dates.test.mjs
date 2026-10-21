import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import * as dates from '../lib/intake/dates.ts';

const now = new Date('2026-10-21T12:00:00Z');
test('frontend and backend share normalization, unknown, partial, future and chronological range cases', () => {
  const cases = JSON.parse(readFileSync(new URL('../../backend/tests/Fixtures/intake-dates.json', import.meta.url), 'utf8'));
  for (const item of cases) {
    if (item.expected) assert.deepEqual(dates.normalizeDate(item.raw, item.config), item.expected);
    assert.equal(dates.dateAnswered(item.raw, item.config, now), item.valid, JSON.stringify(item));
  }
  assert.equal(dates.formatDate('2026-04-30'), '30 april 2026');
  assert.equal(dates.formatDate({ version: 1, precision: 'unknown' }), 'Onbekend');
});

function setup(initial, config = {}) {
  let value = initial;
  const ref = { current: undefined };
  const jsx = (type, props) => ({ type, props });
  const modules = { react: { useRef: () => ref }, 'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable' }, 'lucide-react-native': { Check: 'Check' },
    './IntakeScrollView': { IntakeTextInput: 'Input' }, './MonthSelect': { MonthSelect: 'MonthSelect' }, '@/lib/intake/dates': dates };
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL('../components/intake/MonthYearField.tsx', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText;
  new Function('require', 'exports', js)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  const flat = node => Array.isArray(node) ? node.flatMap(flat) : node && typeof node === 'object' ? [node, ...flat(node.props?.children)] : [];
  return { value: () => value, render: () => flat(exports.MonthYearField({ value, config, label: 'Vaccinatie', onChange: next => { value = next; } })) };
}

test('compact month/year control persists structured data and toggling unknown restores the prior selection', () => {
  const form = setup(undefined);
  form.render().find(n => n.type === 'MonthSelect').props.onChange('7');
  form.render().find(n => n.type === 'Input').props.onChangeText('2026');
  assert.deepEqual(form.value(), { version: 1, precision: 'month', month: 7, year: 2026 });
  assert.equal(form.render().filter(n => n.props.accessibilityRole === 'checkbox').length, 1);
  const toggle = () => form.render().find(n => n.props.accessibilityLabel === 'Weet ik niet').props.onPress();
  toggle();
  assert.deepEqual(form.value(), { version: 1, precision: 'unknown' });
  assert.equal(form.render().find(n => n.type === 'MonthSelect').props.disabled, true);
  toggle();
  assert.deepEqual(form.value(), { version: 1, precision: 'month', month: 7, year: 2026 });
});

test('old free text is retained until explicitly replacing it; no month is invented', () => {
  const form = setup('voorjaar 2024');
  assert.equal(form.render().filter(n => n.type === 'MonthSelect').length, 0);
  assert.equal(form.value(), 'voorjaar 2024');
  form.render().find(n => n.props.accessibilityRole === 'button').props.onPress();
  assert.deepEqual(form.value(), { version: 1, precision: 'month' });
  assert.equal(dates.dateAnswered(form.value(), {}, now), false);
});

test('period controls preserve beginning and end and support ongoing periods', () => {
  const form = setup({ version: 1, precision: 'range', start: { version: 1, precision: 'month', month: 2, year: 2025 } }, { mode: 'range' });
  form.render().find(n => n.props.accessibilityLabel === 'Nog steeds').props.onPress();
  assert.equal(dates.dateAnswered(form.value(), { mode: 'range' }, now), true);
  assert.equal(dates.formatDate(form.value()), 'februari 2025 – heden');
});
