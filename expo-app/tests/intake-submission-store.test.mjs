import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

function load(file, modules) {
 const exports = {};
 const code = ts.transpileModule(readFileSync(new URL(file, import.meta.url), 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
 }).outputText;
 new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
 return exports;
}
const modules = {};
for (const name of ['dates','schema','config','logic','submission-blocks']) modules['./'+name] = load('../lib/intake/'+name+'.ts', modules);
const rules = modules['./submission-blocks'];
const schema = [{id:'custom-safety',fields:[{id:'condition',type:'radio',submissionBlocks:[{answer:'urgent',message:'Stop and contact your vet.'}]}]}];
const config = { recovery: {require: {}} };

// Exercise the actual store's public context with synced booking/horse rows.
// Keep answers hydrated while local submittedAt is optionally still stale, as
// happens on the render before the booking hydration effect runs.
function context({ answers, episode, questionnaire = config, submitted_at = null, completed_at = null, localSubmittedAt = null }) {
 const writes = [];
 const recordWrite = (...args) => { writes.push(args); return Promise.resolve({completed_at:'today'}); };
 const db = { execute: recordWrite, writeTransaction: recordWrite };
 const jsx = (type, props) => ({ type, props });
 const react = {
  createContext: () => ({ Provider: 'IntakeContext' }),
  useState: initial => [typeof initial === 'function' ? initial() : initial?.answers ? { ...initial, answers, submittedAt: localSubmittedAt } : initial, () => {}],
  useRef: current => ({ current }), useCallback: fn => fn, useMemo: fn => fn(), useEffect() {},
 };
 const modules = {
  react, 'react/jsx-runtime': { jsx, jsxs: jsx },
  'react-native': { Alert: { alert: recordWrite } },
  'expo-router': { useGlobalSearchParams: () => ({}) },
  '@powersync/react': { useQuery: sql => ({ isLoading: false, data: sql.includes('FROM horses')
   ? [{ laminitis_safety: JSON.stringify(episode) }]
   : sql.includes('FROM intake_bookings') ? [{ id: 'intake-a', submitted_at, completed_at }] : [] }) },
  '@/db/provider': { useDb: () => ({ powersync: db }) },
  '@/db/hooks': { useCurrentUserId: () => 'owner', useCurrentHorseSelection: () => ({ horseId: 'horse', loading: false }) },
  '@/hooks/useHorseDashboard': { dashboardRequest: recordWrite },
  '@/lib/account-session': { accountSession: { revision: 1 } },
  './schema-provider': { useIntakeSchema: () => ({ schema, noneOptions: [] }) },
  './submission-blocks': rules,
  './persistence': { bookingQuery: 'SELECT * FROM intake_bookings', saveAnswer: recordWrite },
  './submission': {waitForIntakeUploads:async()=>{}}, './refresh': {}, '../refresh-safety': {},
 };
 const { IntakeProvider } = load('../lib/intake/store.tsx', modules);
 let node = jsx(IntakeProvider, { children: null });
 while (typeof node.type === 'function') node = node.type(node.props);
 assert.equal(node.type, 'IntakeContext');
 return { ...node.props.value, writes };
}

for (const completion of [{ submitted_at:'yesterday' }, { completed_at:'yesterday' }, { localSubmittedAt:'yesterday' }]) {
 test(`completed intake remains read-only (${Object.keys(completion)[0]})`, async () => {
  const answers = Object.freeze({'custom-safety':Object.freeze({condition:'none',stable:'old answer'})});
  const store = context({answers,...completion});
  store.setField('custom-safety','condition','urgent'); store.resetSection('custom-safety'); store.reset();
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(store.writes,[]);
  assert.deepEqual(store.state.answers,answers);
 });
}
test('changing an answer saves only that answer and blocks submission immediately',async()=>{
 const answers={'custom-safety':{condition:'none',stable:'existing answer'}};
 const store=context({answers});
 store.setField('custom-safety','condition','urgent');
 await assert.rejects(store.submit(),/Stop and contact your vet/);
 await new Promise(resolve=>setImmediate(resolve));
 const saves=store.writes.filter(row=>row[1]==='intake-a');
 assert.deepEqual(saves.map(row=>row[3]),['condition']);
 assert.equal(answers['custom-safety'].stable,'existing answer');
});

test('a synced reopening unlocks the form and removes obsolete answers while preserving remaining blocking answers',async()=>{
 const slots=[],dependencies=[],writes=[];let cursor=0,effects=[];
 const report={report_id:'report',booking_id:'intake-a',episode_id:'episode',configuration:config,requirements:config.recovery.require};
 const rows={horse:[{laminitis_safety:JSON.stringify({version:2,reports:[report]})}],
  bookings:[{id:'intake-a',submitted_at:'yesterday',completed_at:'yesterday'}],
  answers:[{section_id:'custom-safety',field_id:'stable',value:'"removed approval"'}]};
 const react={createContext:()=>({Provider:'IntakeContext'}),
  useState:initial=>{const i=cursor++;if(!(i in slots))slots[i]=typeof initial==='function'?initial():initial;return[slots[i],v=>{slots[i]=typeof v==='function'?v(slots[i]):v}]},
  useRef:initial=>{const i=cursor++;return slots[i]??=( {current:initial} )},
  useMemo:fn=>fn(),useCallback:fn=>fn,
  useEffect:(fn,deps)=>{const i=cursor++;if(!dependencies[i]||deps.some((d,j)=>d!==dependencies[i][j])){dependencies[i]=deps;effects.push(fn)}}};
 const jsx=(type,props)=>({type,props});
 const modules={react,'react/jsx-runtime':{jsx,jsxs:jsx},'react-native':{Alert:{alert:()=>{}}},
  'expo-router':{useGlobalSearchParams:()=>({})},
  '@powersync/react':{useQuery:sql=>({isLoading:false,data:sql.includes('FROM horses')?rows.horse:sql.includes('FROM intake_bookings')?rows.bookings:rows.answers})},
  '@/db/provider':{useDb:()=>({powersync:{}})},'@/db/hooks':{useCurrentUserId:()=> 'owner',useCurrentHorseSelection:()=> ({horseId:'horse',loading:false})},
  '@/hooks/useHorseDashboard':{dashboardRequest:()=>{}},'@/lib/account-session':{accountSession:{revision:1}},
  './schema-provider':{useIntakeSchema:()=>({schema,noneOptions:[]})},'./submission-blocks':rules,
  './persistence':{bookingQuery:'SELECT * FROM intake_bookings',saveAnswer:async(...args)=>writes.push(args)},
  './submission':{},'./refresh':{},'../refresh-safety':{registerRefreshProtection:()=>()=>{},refreshProtectionChanged:()=>{}}};
 const {IntakeProvider}=load('../lib/intake/store.tsx',modules);
 const render=()=>{let result;for(let pass=0;pass<3;pass++){cursor=0;effects=[];let node=jsx(IntakeProvider,{children:null});while(typeof node.type==='function')node=node.type(node.props);result=node.props.value;effects.forEach(fn=>fn());}return result};
 let store=render();
 assert.equal(store.state.submittedAt,'yesterday');
 store.setField('custom-safety','stable','approved');
 await new Promise(resolve=>setImmediate(resolve));
 assert.deepEqual(writes,[],'completed intake remains locked before server reopening');
 rows.bookings=[{id:'intake-a',submitted_at:null,completed_at:null,updated_at:'today'}];
 rows.answers=[{section_id:'custom-safety',field_id:'condition',value:'"urgent"'}];
 store=render();
 assert.equal(store.state.submittedAt,null);
 assert.equal(store.state.answers['custom-safety'].stable,undefined);
 assert.equal(store.submissionBlocks.length,1);
 store.setField('custom-safety','stable','approved');
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(writes.length,1);
 assert.deepEqual(writes[0].slice(1,5),['intake-a','custom-safety','stable','approved']);
});

test('an immediate safe correction is saved at submission without replaying the old blocking answer',async()=>{
 const store=context({answers:{'custom-safety':{condition:'urgent',stable:'old evidence'}}});
 store.setField('custom-safety','condition','none');
 await store.submit();
 const saves=store.writes.filter(row=>row[1]==='intake-a'&&row[3]==='condition');
 assert.ok(saves.length>0);
 assert.ok(saves.every(row=>row[4]==='none'));
 assert.ok(store.writes.some(row=>row[0]==='/api/intakes/intake-a/submit'));
});
