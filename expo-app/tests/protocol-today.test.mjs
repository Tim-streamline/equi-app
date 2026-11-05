import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
import test from 'node:test';
import ts from 'typescript';
const load = (file, modules) => {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
};
const logic = load('../lib/protocol-today.ts', {});
const buildupLogic = load('../lib/protocol-buildup.ts', {});
const items = [
  { id: 'one', phaseId: 'phase1', phaseTitle: 'Phase A', name: 'Actual A', dosage: '12 g', administration: 1, administrationsPerDay: 1, instructions: 'Instructions from admin.', description: 'Description from admin.' },
  { id: 'two', phaseId: 'phase2', phaseTitle: 'Phase B', name: 'Actual B', dosage: '8 ml', administration: 1, administrationsPerDay: 2 },
  { id: 'two', phaseId: 'phase2', phaseTitle: 'Phase B', name: 'Actual B', dosage: '8 ml', administration: 2, administrationsPerDay: 2 },
];
test('morning and evening completion stay independent across overlapping phases', () => {
  const groups = logic.todayGroups(items, [{ protocolPhaseSupplementId: 'two', administration: 2, done: 1 }]);
  assert.deepEqual(groups.map(g => [g.label, g.items.length, g.done]), [['Ochtend', 2, 0], ['Avond', 1, 1]]);
  assert.equal(logic.administrationKey(items[1]), 'two:1');
  assert.equal(logic.administrationKey(items[2]), 'two:2');
  const old = logic.todayGroups([{ ...items[0], administration: undefined }], [{ protocolPhaseSupplementId: 'one', done: true }]);
  assert.equal(old[0].done, 1);
});

function screen(phases = 2) {
  const state = [], ref = { current: false }, mutations = [], opened = [];
  let local = [];
  let cursor = 0;
  const jsx = (type, props) => ({ type, props });
  const modules = {
    react: { useState: initial => { const i = cursor++; if (!(i in state)) state[i] = initial; return [state[i], value => { state[i] = value; }]; }, useRef: () => ref },
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable', Alert: { alert() {} } },
    'lucide-react-native': { Check: 'Check', ChevronRight: 'ChevronRight', Info: 'Info', Soup: 'Soup' },
    'expo-router': { router: { push: r => opened.push(r) } },
    '@/components/ui/Button': { Button: 'Button' },
    '@/db/hooks': { useSupplementIntakesForDate: () => local, useStoreMutations: () => ({ toggleSupplementIntake: async (...args) => mutations.push(args) }) },
    '@/lib/protocol-buildup': buildupLogic, './DoseBuildupSchedule': { DoseBuildupSchedule: 'DoseBuildupSchedule' }, '@/lib/protocol-today': logic, '@/lib/haptics': { selectionHaptic() {} },
  };
  const component = load('../components/protocol/ProtocolToday.tsx', modules).ProtocolToday;
  const props = { protocol: { id: 'protocol', currentWeek: 2, totalWeeks: 10, dateLabel: 'Actual date', weekLabel: 'Week 2 van 10', today: { items }, phases: [{ id: 'phase1', title: 'Phase A', state: 'active' }, { id: 'phase2', title: 'Phase B', state: phases === 2 ? 'active' : 'locked' }] }, date: '2026-11-03', horseId: 'horse', onPhase: p => opened.push(p.id), SheetComponent: 'Sheet' };
  const render = () => { cursor = 0; return flatten(component(props)); };
  return { render, mutations, opened, props, setLocal: rows => { local = rows; } };
}
const flatten = n => Array.isArray(n) ? n.flatMap(flatten) : n && typeof n === 'object' ? [n, ...flatten(n.props?.children)] : [n];
const copy = nodes => nodes.filter(n => typeof n === 'string').join('');

test('daily progress counts all gifts and updates on local checks and unchecks', () => {
  const app = screen();
  const progress = () => app.render().find(n => n?.props?.accessibilityRole === 'progressbar');
  assert.equal(progress().props.accessibilityValue.now, 0);
  assert.equal(progress().props.accessibilityValue.max, 3);
  const records = items.map(item => ({ protocolPhaseSupplementId: item.id, administration: item.administration, done: true }));
  app.setLocal(records);
  assert.equal(progress().props.accessibilityValue.now, 3);
  assert.equal(progress().props.children.props.style.width, '100%');
  app.setLocal(records.map((row, index) => ({ ...row, done: index === 2 })));
  assert.equal(progress().props.accessibilityValue.now, 1);
  assert.equal(progress().props.children.props.style.width, '33%');
  assert.ok(copy(app.render()).includes('Meng alles door de geweekte bijvoeding'));
});

test('all active phases are accessible and each row uses the actual name and dose', () => {
  const app = screen(); const nodes = app.render();
  assert.ok(copy(nodes).includes('Week 2 van 10') && copy(nodes).includes('Actual date'));
  assert.ok(copy(nodes).includes('Actual A') && copy(nodes).includes('12 g'));
  nodes.find(n => n?.props?.accessibilityLabel === 'Bekijk Phase B').props.onPress();
  assert.deepEqual(app.opened, ['phase2']);
  const single = screen(1).render();
  assert.ok(copy(flatten(single.find(n => n?.props?.accessibilityLabel === 'Bekijk Phase A'))).includes('Uitleg'));
  assert.ok(!single.some(n => n?.props?.accessibilityLabel === 'Bekijk Phase B'));
});

test('Uitleg opens authored instructions without toggling, then marks the selected gift', async () => {
  const app = screen();
  app.render().find(n => n?.props?.accessibilityLabel === 'Uitleg over Actual A, Ochtend').props.onPress();
  let sheet = app.render().find(n => n?.type === 'Sheet');
  assert.equal(sheet.props.visible, true);
  assert.ok(copy(flatten(sheet)).includes('Instructions from admin.'));
  assert.ok(copy(flatten(sheet)).includes('Description from admin.'));
  assert.equal(app.mutations.length, 0);
  app.render().find(n => n?.props?.accessibilityLabel === 'Uitleg over Actual B, Avond').props.onPress();
  sheet = app.render().find(n => n?.type === 'Sheet');
  flatten(sheet.props.footer).find(n => n?.props?.title === 'Markeer als gegeven').props.onPress();
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(app.mutations, [['two', '8 ml', '2026-11-03', 'horse', 2]]);
  assert.equal(app.render().find(n => n?.type === 'Sheet').props.visible, false);
});

test('actual local mutation persists each gift and still handles old rows without a gift number', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    db.exec('CREATE TABLE protocol_supplement_intakes (id TEXT PRIMARY KEY,protocol_phase_supplement_id TEXT,horse_id TEXT,date TEXT,dosage TEXT,administration INTEGER,done INTEGER,taken_at TEXT)');
    const source = readFileSync(new URL('../db/hooks.ts', import.meta.url), 'utf8');
    const code = ts.transpileModule(source.slice(source.indexOf('export function useStoreMutations(')), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    const exports = {}; let id = 0;
    new Function('exports', 'usePowerSync', 'useMemo', 'newId', code)(exports, () => ({
      getOptional: async (sql, args) => db.prepare(sql).get(...args), execute: async (sql, args) => db.prepare(sql).run(...args),
    }), fn => fn(), () => String(++id));
    const mutation = exports.useStoreMutations().toggleSupplementIntake;
    await mutation('product', '10 g', '2026-11-03', 'horse', 1);
    await mutation('product', '20 g', '2026-11-03', 'horse', 2);
    assert.deepEqual(db.prepare('SELECT dosage FROM protocol_supplement_intakes ORDER BY administration').all().map(r => r.dosage), ['10 g', '10 g']);
    await mutation('product', '10 g', '2026-11-03', 'horse', 1);
    assert.deepEqual(db.prepare('SELECT administration,done FROM protocol_supplement_intakes ORDER BY administration').all().map(r => [r.administration,r.done]), [[1,0],[2,1]]);
    db.exec('UPDATE protocol_supplement_intakes SET administration = NULL WHERE administration = 1');
    await mutation('product', '10 g', '2026-11-03', 'horse');
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM protocol_supplement_intakes').get().n, 2);
  } finally { db.close(); }
});


test('recorded local buildup doses survive refreshed server amounts and fix other gifts that day', async () => {
  const app = screen();
  app.setLocal([{protocolPhaseSupplementId: 'two', administration: 1, done: false, dosage: '2 ml'}]);
  const nodes = app.render();
  assert.ok(copy(nodes).includes('2 ml'));
  assert.ok(!copy(nodes).includes('8 ml'));
  nodes.find(n => n?.props?.accessibilityLabel === 'Uitleg over Actual B, Avond').props.onPress();
  const sheet = app.render().find(n => n?.type === 'Sheet');
  flatten(sheet.props.footer).find(n => n?.props?.title === 'Markeer als gegeven').props.onPress();
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(app.mutations, [['two', '2 ml', '2026-11-03', 'horse', 2]]);
});

const buildup = { date: '2026-11-03', currentStep: 3, totalSteps: 4, currentDosage: '10 g', previousDosage: '5 g', fullDosage: '15 g', next: {day:4,dosage:'15 g',afterRecordedDays:1}, schedule: [{day:1,dosage:'5 g'},{day:2,dosage:'5 g'},{day:3,dosage:'10 g'},{day:4,dosage:'15 g'}] };
test('one compact buildup reminder stays visible after completing all gifts',()=>{
  const app=screen();app.props.protocol.today.items=items.map(item=>({...item,dosage:'10 g',done:true,buildup}));
  let nodes=app.render();assert.ok(copy(nodes).includes('Vandaag een nieuwe opbouwdag'));
  assert.equal(nodes.filter(n=>n==='Vandaag een nieuwe opbouwdag').length,1);
  assert.ok(copy(nodes).includes('Opbouwdosering'));
  assert.ok(!nodes.some(n=>n?.props?.accessibilityLabel?.startsWith('Bekijk nieuwe dosering')));
  nodes.find(n=>n?.props?.accessibilityLabel==='Uitleg over Actual B, Ochtend').props.onPress();
  const detail=app.render().find(n=>n?.type==='DoseBuildupSchedule');assert.equal(detail.props.item.id,'two');assert.equal(detail.props.date,'2026-11-03');
});
test('local recorded amount wins, and stale schedules never predict a new dose',()=>{
  assert.equal(buildupLogic.buildupState({...items[0],dosage:'5 g',buildup},'2026-11-03').changed,false);
  assert.equal(buildupLogic.buildupState({...items[0],dosage:'5 g',buildup},'2026-11-03').next,null);
  assert.equal(buildupLogic.buildupState({...items[0],buildup},'2026-11-04'),null);
  const schedule=buildupLogic.buildupSchedule({...items[0],dosage:'10 g',buildup},'2026-11-03');
  assert.deepEqual(schedule.map(row=>[row.from,row.to,row.current]),[[1,2,false],[3,3,true],[4,4,false]]);
  assert.deepEqual(buildupLogic.dailyDoseChanges(items,'2026-11-03'),[]);
});

test('the actual schedule groups equal doses and highlights the present step',()=>{
  const jsx=(type,props)=>({type,props});
  const component=load('../components/protocol/DoseBuildupSchedule.tsx',{'react/jsx-runtime':{jsx,jsxs:jsx,Fragment:'Fragment'},'react-native':{View:'View',Text:'Text'},'@/lib/protocol-buildup':buildupLogic}).DoseBuildupSchedule;
  const text=copy(flatten(component({item:{...items[0],dosage:'10 g',buildup},date:'2026-11-03'})));
  assert.ok(text.includes('Dag 1–2'));assert.ok(text.includes('Huidige stap'));assert.ok(text.includes('Vandaag: 10 g'));
  assert.equal(component({item:items[0]}),null);
  assert.equal(buildupLogic.buildupState({...items[0],dosage:'5.0 g',buildup},'2026-11-03').changed,false);
});
