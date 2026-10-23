import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import * as dates from '../lib/intake/dates.ts';

const modules = { './dates': dates };
for (const name of ['schema', 'config', 'logic']) {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL(`../lib/intake/${name}.ts`, import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  new Function('require', 'exports', js)(key => { assert.ok(key in modules, key); return modules[key]; }, exports);
  modules[`./${name}`] = exports;
}
const logic = modules['./logic'];

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

const rangeValue = () => ({ version: 1, precision: 'range',
  start: { version: 1, precision: 'month', month: 2, year: 2024 },
  end: { version: 1, precision: 'month', month: 7, year: 2025 } });
const clearRangeControl = (form, control) => {
  const [endpoint, part] = control.split('.');
  const index = endpoint === 'start' ? 0 : 1;
  if (part === 'month') form.render().filter(n => n.type === 'MonthSelect')[index].props.onChange('');
  else form.render().filter(n => n.type === 'Input')[index].props.onChangeText('');
};
for (const optional of [true, false]) {
  for (const order of [
    ['start.month', 'start.year', 'end.month', 'end.year'],
    ['end.year', 'end.month', 'start.year', 'start.month'],
    ['start.month', 'end.month', 'start.year', 'end.year'],
    ['end.year', 'start.year', 'end.month', 'start.month'],
  ]) {
    test(`${optional ? 'optional' : 'required'} range clears only after ${order.join(', ')}`, () => {
      const form = setup(rangeValue(), { mode: 'range' });
      const field = { id: 'period', type: 'month_year', optional, dateConfig: { mode: 'range' } };
      const section = { id: 'test', fields: [field] };
      for (const [index, control] of order.entries()) {
        clearRangeControl(form, control);
        assert.equal(logic.isFieldAnswered(field, form.value()), false);
        if (index < 3) {
          assert.equal(form.value()?.precision, 'range', 'retain partial ranges');
          assert.equal(form.render().some(n => n.props.accessibilityRole === 'alert'), true);
        }
      }
      assert.equal(form.value(), undefined);
      assert.ok(form.render().filter(n => ['MonthSelect', 'Input'].includes(n.type)).every(n => n.props.value === ''));
      assert.equal(form.render().some(n => n.props.accessibilityRole === 'alert'), false);
      assert.equal(logic.isSectionComplete(section, { period: form.value() }), optional);
    });
  }
}

test('an ongoing selection keeps an empty-start range invalid until it is unchecked', () => {
  const form = setup({ version: 1, precision: 'range', start: rangeValue().start, ongoing: true }, { mode: 'range' });
  clearRangeControl(form, 'start.year');
  clearRangeControl(form, 'start.month');
  assert.equal(form.value().ongoing, true);
  assert.equal(dates.dateAnswered(form.value(), { mode: 'range' }, now), false);
  form.render().find(n => n.props.accessibilityLabel === 'Nog steeds').props.onPress();
  assert.equal(form.value(), undefined);
});

test('a selected unknown month is a partial range, not an empty range', () => {
  const form = setup(undefined, { mode: 'range' });
  form.render().find(n => n.type === 'MonthSelect').props.onChange('year');
  assert.equal(form.value().start.precision, 'year');
  assert.equal(dates.dateAnswered(form.value(), { mode: 'range' }, now), false);
  clearRangeControl(form, 'start.month');
  assert.equal(form.value(), undefined);
});

for (const optional of [true, false]) {
  for (const order of [['month', 'year'], ['year', 'month']]) {
    test(`${optional ? 'optional' : 'required'} date can be cleared ${order.join(' then ')}`, () => {
      const initial = { version: 1, precision: 'month', month: 7, year: 2025 };
      const form = setup(initial);
      const field = { id: 'date', type: 'month_year', optional };
      const section = { id: 'test', fields: [field] };
      const complete = () => logic.isSectionComplete(section, { date: form.value() });
      const clear = part => part === 'month'
        ? form.render().find(n => n.type === 'MonthSelect').props.onChange('')
        : form.render().find(n => n.type === 'Input').props.onChangeText('');
      assert.equal(complete(), true);
      clear(order[0]);
      assert.equal(logic.isFieldAnswered(field, form.value()), false);
      assert.equal(form.value()[order[1]], initial[order[1]], 'retain the component not yet cleared');
      assert.equal(form.render().some(n => n.props.accessibilityRole === 'alert'), true);
      clear(order[1]);
      assert.equal(form.value(), undefined);
      assert.equal(form.render().find(n => n.type === 'MonthSelect').props.value, '');
      assert.equal(form.render().find(n => n.type === 'Input').props.value, '');
      assert.equal(form.render().some(n => n.props.accessibilityRole === 'alert'), false);
      assert.equal(complete(), optional);
      assert.equal(logic.isFieldAnswered(field, form.value()), false, 'an empty required date remains unanswered');
      form.render().find(n => n.type === 'Input').props.onChangeText('2025');
      form.render().find(n => n.type === 'MonthSelect').props.onChange('7');
      assert.deepEqual(form.value(), initial, 'refilling keeps the structured storage format');
      assert.equal(complete(), true);
    });
  }
}
