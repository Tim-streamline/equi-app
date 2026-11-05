import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

function load(file, modules = {}) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  new Function('require', 'exports', code)(name => {
    assert.ok(name in modules, name);
    return modules[name];
  }, exports);
  return exports;
}

const daily = load('../lib/protocol-today.ts');
const buildup = load('../lib/protocol-buildup.ts');
const flatten = node => Array.isArray(node) ? node.flatMap(flatten)
  : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];

function screen(records = []) {
  let detail = null;
  const jsx = (type, props) => ({ type, props });
  const component = load('../components/protocol/ProtocolPhaseContent.tsx', {
    react: { useState: () => [detail, value => { detail = value; }] },
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable' },
    'lucide-react-native': { ChevronRight: 'ChevronRight' },
    '@/components/ui/Button': { Button: 'Button' },
    '@/db/hooks': { useSupplementIntakesForDate: date => records.filter(row => row.date === date) },
    '@/lib/protocol-today': daily,
    '@/lib/protocol-calendar': load('../lib/protocol-calendar.ts'),
    './DoseBuildupSchedule': { DoseBuildupSchedule: 'Schedule' },
  }).ProtocolPhaseContent;
  const item = {
    id: 'herb', name: 'Herb', dosage: '20 g', administrationsPerDay: 2,
    buildup: {
      date: '2026-11-05', currentDosage: '10 g', currentStep: 2, totalSteps: 3,
      previousDosage: '5 g', fullDosage: '20 g',
      next: { day: 3, dosage: '20 g', afterRecordedDays: 1 },
      schedule: [{ day: 1, dosage: '5 g' }, { day: 2, dosage: '10 g' }, { day: 3, dosage: '20 g' }],
    },
  };
  const props = {
    date: '2026-11-05', phase: { id: 'phase', state: 'active', contentAvailable: true, supplements: [item] },
    orderItems: [], onRefresh: async () => {},
  };
  const render = () => flatten(component(props));
  const open = () => render().find(node => node?.props?.accessibilityLabel === 'Uitleg over Herb').props.onPress();
  return { render, open, props, item };
}

for (const administration of [1, 2]) {
  test(`phase overview and detail preserve the saved gift ${administration} dose, even when unchecked`, () => {
    const records = [{ date: '2026-11-05', protocolPhaseSupplementId: 'herb', administration, dosage: '5 g', done: 0 }];
    const app = screen(records);
    assert.ok(app.render().includes('5 g'));
    assert.ok(!app.render().includes('10 g'));
    app.open();
    const schedule = app.render().find(node => node?.type === 'Schedule').props;
    const todayDose = daily.todayGroups([{ ...app.item, dosage: '10 g', administration: 1 }], records)[0].items[0].dosage;
    assert.equal(schedule.item.dosage, todayDose);
    assert.equal(schedule.date, app.props.date);
    assert.equal(buildup.buildupState(schedule.item, schedule.date).currentStep, null);
    assert.equal(buildup.buildupState(schedule.item, schedule.date).next, null);
    assert.ok(buildup.buildupSchedule(schedule.item, schedule.date).every(row => !row.current));
  });
}

test('phase uses the server effective dose when there is no saved dose for this product and date', () => {
  const app = screen([
    { date: '2026-11-04', protocolPhaseSupplementId: 'herb', dosage: '5 g' },
    { date: '2026-11-05', protocolPhaseSupplementId: 'other', dosage: '5 g' },
  ]);
  assert.ok(app.render().includes('10 g'));
  assert.ok(!app.render().includes('20 g'));
  app.open();
  const schedule = app.render().find(node => node?.type === 'Schedule').props;
  assert.equal(buildup.buildupState(schedule.item, schedule.date).currentStep, 2);
});

test('an open phase detail reacts to local intake updates and uses the dashboard date for schedule freshness', () => {
  const records = [];
  const app = screen(records);
  app.open();
  assert.ok(app.render().includes('10 g'));
  records.push({ date: '2026-11-05', protocolPhaseSupplementId: 'herb', administration: 2, dosage: '5 g', done: 1 });
  assert.ok(app.render().includes('5 g'));
  app.props.date = '2026-11-06';
  const schedule = app.render().find(node => node?.type === 'Schedule').props;
  assert.equal(schedule.date, '2026-11-06');
  assert.equal(buildup.buildupState(schedule.item, schedule.date), null);
});
