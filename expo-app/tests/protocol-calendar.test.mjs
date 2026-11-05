import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
const load = (file, modules = {}) => {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  new Function('require','exports',code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
};
const logic = load('../lib/protocol-calendar.ts');
const daily = load('../lib/protocol-today.ts');
const phases = [{ id:'a', title:'Real phase A', startsAt:'2026-11-01T00:00:00+01:00', state:'active' }, { id:'b', title:'Real phase B', startsAt:'2026-11-15T00:00:00+01:00', state:'locked' }, { id:'c', title:'Real phase C', startsAt:'2026-11-15T00:00:00+01:00', state:'locked' }];

test('future phase markers group simultaneous starts and stay absent before owner start', () => {
  const protocol = { phases };
  assert.deepEqual(logic.nextPhaseStart(protocol,'2026-11-03').map(p=>p.id), ['b','c']);
  assert.equal(logic.phaseStarts(protocol,'2026-11-15').length,2);
  assert.deepEqual(logic.phaseStarts({...protocol,preparing:true},'2026-11-15'),[]);
  assert.deepEqual(logic.nextPhaseStart({...protocol,preparing:true},'2026-11-03'),[]);
});
test('future quantities contain only selected phases and never guess missing doses', () => {
  const row = {id:'herb',name:'Real herb',unit:'g',components:[{phaseId:'a',selectionId:'one',quantity:420},{phaseId:'b',selectionId:'two',quantity:1260}],purchaseOptions:[]};
  const result = logic.phaseOrderItems([row],['b'])[0];
  assert.equal(result.quantityLabel,'1,26 kg'); assert.equal(result.quantity,1260);
  assert.deepEqual(result.selectionIds,['two']); assert.equal(row.components.length,2);
  assert.equal(logic.phaseOrderItems([{...row,components:[{phaseId:'b',quantity:null}]}],['b'])[0].quantityLabel,null);
  assert.deepEqual(logic.phaseOrderItems([row],['c']),[]);
});
test('rings reflect exact daily completion and handle old or empty calendars', () => {
  assert.equal(logic.dayProgress({total:3,done:1,state:'partial'}),1/3);
  assert.equal(logic.dayProgress({total:0,done:0,state:'default'}),null);
  assert.equal(logic.dayProgress({state:'complete'}),1);
  assert.equal(logic.dayProgress({state:'default'}),null);
});
const flatten = n => Array.isArray(n) ? n.flatMap(flatten) : n && typeof n === 'object' ? [n,...flatten(n.props?.children)] : [n];
function screen(editable=true) {
  const states=[], refs=[]; let hook=0, local=[]; const calls=[], opened=[];
  const items=[{id:'herb',name:'Real herb',dosage:'15 g',instructions:'Real instructions',administration:1,administrationsPerDay:2,done:false},{id:'herb',name:'Real herb',dosage:'15 g',administration:2,administrationsPerDay:2,done:false}];
  const day={date:'2026-11-02',label:'Actual date',editable,items,state:'missed'};
  const jsx=(type,props)=>({type,props});
  const modules={react:{useEffect(){},useRef:value=>{const i=hook++;return refs[i]??=( {current:value} );},useState:initial=>{const i=hook++;if(!(i in states))states[i]=initial;return[states[i],value=>states[i]=typeof value==='function'?value(states[i]):value];}},'react/jsx-runtime':{jsx,jsxs:jsx,Fragment:'Fragment'},'react-native':Object.fromEntries(['View','Text','Pressable','ActivityIndicator'].map(n=>[n,n])), 'react-native-svg':{default:'Svg',Circle:'Circle'}, 'lucide-react-native':Object.fromEntries(['Check','ChevronLeft','ChevronRight','ShoppingBag'].map(n=>[n,n])), '@/components/ui/Button':{Button:'Button'},'@/db/hooks':{useSupplementIntakesForDate:()=>local,useStoreMutations:()=>({toggleSupplementIntake:async(id,dose,date,horse,n)=>{local=[{protocolPhaseSupplementId:id,administration:n,done:1}];}})},'@/hooks/useHorseDashboard':{deviceTimezone:()=> 'Europe/Amsterdam',dashboardRequest:async(url,body)=>{calls.push({url,body});return body?{...day,state:'partial',items:items.map(item=>({...item,done:item.administration===body.administration}))}:day;}},'@/lib/protocol-today':daily,'./DoseBuildupSchedule':{DoseBuildupSchedule:'DoseBuildupSchedule'},'@/lib/protocol-calendar':logic,'@/lib/haptics':{selectionHaptic(){}}};
  const component=load('../components/protocol/ProtocolCalendar.tsx',modules).ProtocolCalendar;
  const props={protocol:{id:'p',phases,today:{items},calendar:{label:'Actual month',previousMonth:'2026-10',nextMonth:'2026-12',cells:[{date:'2026-11-02',day:2,available:true,state:'missed',total:2,done:0},{date:'2026-11-03',day:3,isToday:true,state:'default'},{date:'2026-11-15',day:15,state:'default'}]}},today:'2026-11-03',horseId:'h',onMonth:m=>opened.push(m),onPhase:p=>opened.push(p.id),onToday:()=>opened.push('today'),onOrder:ids=>opened.push(ids),onRefresh:async()=>{},SheetComponent:'Sheet'};
  const render=()=>{hook=0;return flatten(component(props));};
  const open=async()=>{await render().find(n=>n?.props?.accessibilityLabel?.startsWith('maandag 2')).props.onPress();};
  return{render,open,calls,opened,setLocal:rows=>local=rows};
}
test('day sheet groups doses and Uitleg never changes a checkbox; historical evening saves separately', async()=>{
  const app=screen();await app.open();
  let nodes=app.render();assert.ok(nodes.includes('Ochtend') && nodes.includes('Avond'));
  nodes.find(n=>n?.props?.accessibilityLabel==='Uitleg over Real herb, Ochtend').props.onPress();
  nodes=app.render();assert.ok(nodes.includes('Real instructions'));assert.equal(app.calls.length,1);
  nodes.find(n=>n?.props?.title==='Terug naar daglijst').props.onPress();
  await app.render().find(n=>n?.props?.accessibilityLabel==='Real herb, Avond, 15 g').props.onPress();
  assert.equal(app.calls[1].body.administration,2);assert.equal(app.calls[1].body.date,'2026-11-02');
});
test('readonly history cannot be checked and calendar navigation preserves month and Today actions',async()=>{
  const app=screen(false);await app.open();
  const check=app.render().find(n=>n?.props?.accessibilityLabel==='Real herb, Ochtend, 15 g');assert.equal(check.props.disabled,true);
  await check.props.onPress();assert.equal(app.calls.length,1);
  app.render().find(n=>n?.props?.accessibilityLabel==='Volgende maand').props.onPress();
  app.render().find(n=>n?.props?.accessibilityLabel?.startsWith('dinsdag 3')).props.onPress();
  assert.deepEqual(app.opened,['2026-12']);assert.ok(app.render().some(n=>n?.type==='Sheet'&&n.props.visible));
});

test('order reminder opens the grouped future phases without marking any day',()=>{const app=screen();app.render().find(n=>n?.props?.accessibilityLabel==='Bestellijst voor de volgende fases').props.onPress();assert.deepEqual(app.opened,[['b','c']]);assert.equal(app.calls.length,0);});

 test('order reminder starts exactly fourteen days before a phase',()=>{
   assert.deepEqual(logic.nextPhaseStart({phases:phases.slice(1)},'2026-10-31'),[]);
   assert.deepEqual(logic.nextPhaseStart({phases:phases.slice(1)},'2026-11-01').map(p=>p.id),['b','c']);
 });
 test('phase lanes stay fixed and clip correctly at month and week edges',()=>{
   const week=[null,null,null,null,null,null,{date:'2026-11-01'}];
   const timeline=[{id:'a',startsAt:'2026-10-15',endsAt:'2026-12-01'},{id:'b',startsAt:'2026-11-01',endsAt:'2026-11-08'},{id:'c',startsAt:'2026-11-15',endsAt:'2026-12-15'}];
   assert.deepEqual(logic.weekPhaseLines(week,timeline).map(l=>[l.lane,l.start,l.length]),[[0,6,1],[1,6,1],[2,0,0]]);
   const later=Array.from({length:7},(_,i)=>({date:'2026-11-'+(16+i)}));
   assert.deepEqual(logic.weekPhaseLines(later,timeline).map(l=>[l.lane,l.length]),[[0,7],[1,0],[2,7]]);
   assert.ok(logic.weekPhaseLines(later,timeline,true).every(l=>l.length===0));
 });
 test('today opens the day sheet and checks a gift through local storage',async()=>{
   const app=screen();app.render().find(n=>n?.props?.accessibilityLabel?.startsWith('dinsdag 3')).props.onPress();
   assert.equal(app.calls.length,0);
   await app.render().find(n=>n?.props?.accessibilityLabel==='Real herb, Avond, 15 g').props.onPress();
   const ring=app.render().find(n=>n?.props?.progress===.5);assert.ok(ring);
   assert.equal(app.calls.length,0);
 });
 test('future preview contains order totals and description without per-gift doses',()=>{
   let detail=null;const jsx=(type,props)=>({type,props});
   const component=load('../components/protocol/ProtocolPhaseContent.tsx',{
     react:{useState:()=>[detail,value=>detail=value]},'react/jsx-runtime':{jsx,jsxs:jsx,Fragment:'Fragment'},'react-native':{View:'View',Text:'Text',Pressable:'Pressable'},'lucide-react-native':{ChevronRight:'ChevronRight'},'@/components/ui/Button':{Button:'Button'},'./DoseBuildupSchedule':{DoseBuildupSchedule:'DoseBuildupSchedule'},'@/lib/protocol-calendar':logic,
   }).ProtocolPhaseContent;
   const phase={id:'b',state:'preview',description:'Authored phase description',supplements:[{id:'x',name:'Herb',dosage:'SECRET DOSE',instructions:'SECRET INSTRUCTIONS'}]};
   const props={phase,orderItems:[{id:'x',name:'Herb',unit:'g',components:[{phaseId:'b',quantity:420}]}],onRefresh:async()=>{}};
   const nodes=flatten(component(props));assert.ok(nodes.includes('420 g'));assert.ok(nodes.includes('Authored phase description'));assert.ok(!nodes.includes('SECRET DOSE'));assert.ok(!nodes.includes('SECRET INSTRUCTIONS'));
   phase.state='active';component(props);const active=flatten(component(props));active.find(n=>n?.props?.accessibilityLabel==='Uitleg over Herb').props.onPress();assert.ok(flatten(component(props)).includes('SECRET INSTRUCTIONS'));
 });
