import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
function load(file, modules = {}) {
  const exports = {};
  new Function('require', 'exports', ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), { compilerOptions: {module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX} }).outputText)(name => {assert.ok(name in modules, name);return modules[name];}, exports);
  return exports;
}
const schema = load('../lib/intake/schema.ts');
const logic = load('../lib/intake/logic.ts', {'./schema':schema, './config':load('../lib/intake/config.ts', {'./schema':schema})});
const field = schema.INTAKE_SCHEMA.find(s=>s.id==='paard').fields.find(f=>f.id==='leeftijd');
const {ageFromBirthdate, isFieldAnswered, answeredCount} = logic;

test('birthdate parsing handles birthdays, leap days, legacy formats and rejects invented dates', () => {
 const today = new Date(2026, 9, 16);
 for (const [date, age] of [['2016-10-16',10],['17-10-2016',9],['2024-02-29',2],['2026-10-16',0]]) assert.equal(ageFromBirthdate(date,today),age);
 for (const date of ['2023-02-29','31-04-2020','2027-01-01','2016','',null,'2020-13-10']) assert.equal(ageFromBirthdate(date,today),undefined);
});
test('one answer is sufficient: either a whole estimated age or a valid date', () => {
 assert.equal(isFieldAnswered({type:'repeater'}, [{_source:'balancer',product:''}]),false);
 for (const age of [0,5,'17']) assert.ok(isFieldAnswered(field,age,{paard:{leeftijd:age}},'paard'));
 for (const age of [-1,1.5,'',undefined,'unknown']) assert.equal(isFieldAnswered(field,age,{},'paard'),false);
 assert.ok(isFieldAnswered(field,undefined,{paard:{geboortedatum:'2020-01-01'}},'paard'));
 assert.equal(isFieldAnswered(field,12,{paard:{geboortedatum:'31-02-2020'}},'paard'),false);
 assert.deepEqual(answeredCount({id:'paard',fields:[field]},{leeftijd:12},{paard:{leeftijd:12}}),{answered:1,total:1});
});
const flatten=n=>Array.isArray(n)?n.flatMap(flatten):n&&typeof n==='object'?[n,...flatten(n.props?.children)]:[];
test('offline age control uses configured keys, reveals date on request, saves both, restores and permits an estimate again', () => {
 const state={answers:{custom:{years:12}}}; let expanded=false; let effects=[];
 const setField=(s,k,v)=>{(state.answers[s]??={})[k]=v;};
 const config={...field.ageConfig,date_field:'born',date_label:'Custom date',toggle_label:'Show custom date'};
 const custom={...field,id:'years',ageConfig:config};
 const jsx=(type,props)=>({type,props});
 const {AgeField}=load('../components/intake/AgeField.tsx',{
  react:{useState:init=>[expanded, v=>{expanded=v;}], useEffect:fn=>effects.push(fn)},
  'react/jsx-runtime':{jsx,jsxs:jsx},'react-native':{View:'View',Text:'Text',Pressable:'Pressable'},
  './IntakeScrollView':{IntakeTextInput:'Input'},'@/lib/intake/logic':logic,
  '@/lib/intake/store':{useIntake:()=>({state,setField})},
 });
 const draw=()=>{effects=[];const result=flatten(AgeField({field:custom,sectionId:'custom'}));effects.forEach(fn=>fn());return result;};
 assert.equal(draw().filter(n=>n.type==='Input').length,1);
 draw().find(n=>n.type==='Pressable').props.onPress();
 const date=()=>draw().find(n=>n.type==='Input'&&n.props.accessibilityLabel==='Custom date');
 date().props.onChangeText('2020-01-01'); draw();
 assert.equal(state.answers.custom.years,ageFromBirthdate('2020-01-01'));
 assert.equal(state.answers.custom.born,'2020-01-01');
 assert.ok(!draw().some(n=>n.type==='Text' && n.props.children===config.calculated_hint));
 expanded=false; // Remount/reload from persisted answers still reveals the saved date.
 assert.equal(date().props.value,'2020-01-01');
 assert.equal(draw().find(n=>n.type==='Input').props.editable,false);
 draw().find(n=>n.type==='Pressable').props.onPress();
 assert.equal(state.answers.custom.born,undefined);
 draw().find(n=>n.type==='Input').props.onChangeText('9');
 assert.equal(state.answers.custom.years,9);
 assert.equal(draw().filter(n=>n.type==='Input').length,1);
});
