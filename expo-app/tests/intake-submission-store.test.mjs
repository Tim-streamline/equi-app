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
function context({ answers, episode, questionnaire = config, submitted_at = null, completed_at = null, localSubmittedAt = null,
 save = async () => {}, waitForUploads = async () => {}, request = async () => ({ completed_at: 'today' }), execute = async () => {} }) {
 const writes = [];
 const recordWrite = (...args) => { writes.push(args); return Promise.resolve({completed_at:'today'}); };
 const db = { execute: async (...args) => { writes.push(args); return execute(...args); }, writeTransaction: recordWrite };
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
  '@/hooks/useHorseDashboard': { dashboardRequest: async (...args) => { writes.push(args); return request(...args); } },
  '@/lib/account-session': { accountSession: { revision: 1 } },
  './schema-provider': { useIntakeSchema: () => ({ schema, noneOptions: [] }) },
  './submission-blocks': rules,
  './persistence': { bookingQuery: 'SELECT * FROM intake_bookings', saveAnswer: async (...args) => { writes.push(args); return save(...args); } },
  './submission': {waitForIntakeUploads:waitForUploads}, './refresh': {}, '../refresh-safety': {},
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

test('finishing a fully synced intake only sends the completion request, without replaying its answers', async () => {
 const answers = { 'custom-safety': { condition: 'none', ...Object.fromEntries(Array.from({length:216}, (_,i) => [`field-${i}`, `answer-${i}`])) } };
 const store = context({ answers, save: async () => assert.fail('Already saved answers must not be written again') });
 await store.submit();
 assert.deepEqual(store.writes, [['/api/intakes/intake-a/submit', {}]]);
});

test('a last-second edit saves once and finishes uploading before the completion request', async () => {
 const events = [];
 let finishSave, finishUpload;
 const store = context({ answers: { 'custom-safety': { condition: 'none', stable: 'old' } },
  save: async () => { events.push('save'); await new Promise(resolve => { finishSave = resolve; }); },
  waitForUploads: async () => { events.push('wait-upload'); await new Promise(resolve => { finishUpload = resolve; }); },
  request: async () => { events.push('complete'); return {completed_at:'today'}; },
 });
 store.setField('custom-safety', 'stable', 'new');
 const submitted = store.submit();
 await new Promise(resolve => setImmediate(resolve));
 assert.deepEqual(events, ['save']);
 finishSave();
 await new Promise(resolve => setImmediate(resolve));
 assert.deepEqual(events, ['save', 'wait-upload']);
 finishUpload();
 await submitted;
 assert.deepEqual(events, ['save', 'wait-upload', 'complete']);
 assert.equal(store.writes.filter(row => row[1] === 'intake-a').length, 1);
});

test('completion retries only the latest failed local autosave of an answer', async () => {
 const persisted = [];
 let fail = true;
 const store = context({ answers: { 'custom-safety': { condition: 'none', stable: 'old', untouched: 'already saved' } },
  save: async (...args) => { if (fail) throw new Error('Local save unavailable'); persisted.push(args.slice(2,5)); },
 });
 store.setField('custom-safety', 'stable', 'first edit');
 store.setField('custom-safety', 'stable', 'latest edit');
 await new Promise(resolve => setImmediate(resolve));
 assert.deepEqual(persisted, []);
 fail = false;
 await store.submit();
 assert.deepEqual(persisted, [['custom-safety','stable','latest edit']]);
 assert.equal(store.writes.filter(row => row[0] === '/api/intakes/intake-a/submit').length, 1);
});

test('a failed local write prevents completion and a later retry does not rewrite successful answers', async () => {
 let fail = true;
 const store = context({ answers: { 'custom-safety': { condition: 'none', stable: 'old' } },
  save: async () => { if (fail) throw new Error('Local storage unavailable'); },
 });
 store.setField('custom-safety', 'stable', 'new');
 await assert.rejects(store.submit(), /Local storage unavailable/);
 assert.equal(store.writes.some(row => row[0] === '/api/intakes/intake-a/submit'), false);
 fail = false;
 await store.submit();
 assert.equal(store.writes.filter(row => row[0] === '/api/intakes/intake-a/submit').length, 1);
 assert.ok(store.writes.filter(row => row[1] === 'intake-a').every(row => row[3] === 'stable'));
});

test('a failed section reset is retried before completion, so cleared answers cannot silently return', async () => {
 let fail = true;
 const events = [];
 const store = context({ answers: { 'custom-safety': { condition: 'none' } },
  execute: async () => { if (fail) throw new Error('Local delete unavailable'); events.push('reset'); },
  request: async () => { events.push('complete'); return {completed_at:'today'}; },
 });
 store.resetSection('custom-safety');
 await assert.rejects(store.submit(), /Local delete unavailable/);
 assert.deepEqual(events, []);
 fail = false;
 await store.submit();
 assert.deepEqual(events, ['reset','complete']);
});

test('retrying after upload or completion failure never resaves the answer snapshot', async () => {
 for (const phase of ['upload','completion']) {
  let fail = true;
  const store = context({ answers: { 'custom-safety': { condition: 'none', stable: 'saved' } },
   save: async () => assert.fail('Retry must not replay saved answers'),
   waitForUploads: async () => { if (fail && phase === 'upload') throw new Error('Upload pending'); },
   request: async () => { if (fail && phase === 'completion') throw new Error('Network unavailable'); return {completed_at:'today'}; },
  });
  await assert.rejects(store.submit(), phase === 'upload' ? /Upload pending/ : /Network unavailable/);
  assert.equal(store.writes.length, phase === 'upload' ? 0 : 1);
  fail = false;
  await store.submit();
  assert.ok(store.writes.every(row => row[0] === '/api/intakes/intake-a/submit'));
 }
});

test('a failing local write is reported once, not again for every edit queued behind it', async () => {
 const store = context({ answers: { 'custom-safety': { condition: 'none' } },
  save: async () => { throw new Error('Local storage unavailable'); },
 });
 store.setField('custom-safety', 'first', 'a');
 store.setField('custom-safety', 'second', 'b');
 await new Promise(resolve => setImmediate(resolve));
 assert.equal(store.writes.filter(row => row[0] === 'Intake niet opgeslagen').length, 1);
});

test('retrying saves the answers that were kept after a failed write', async () => {
 let fail = true;
 const store = context({ answers: { 'custom-safety': { condition: 'none' } },
  save: async () => { if (fail) throw new Error('Local storage unavailable'); },
 });
 store.setField('custom-safety', 'first', 'a');
 store.setField('custom-safety', 'second', 'b');
 await new Promise(resolve => setImmediate(resolve));
 const saved = () => store.writes.filter(row => row[1] === 'intake-a').map(row => row[3]);
 assert.deepEqual(saved(), ['first', 'second', 'first', 'second']);
 fail = false;
 store.retrySave();
 await new Promise(resolve => setImmediate(resolve));
 assert.deepEqual(saved().slice(4), ['first', 'second']);
 assert.equal(store.writes.filter(row => row[0] === 'Intake niet opgeslagen').length, 1);
});

test('one answer that keeps failing does not stop unrelated answers from saving', async () => {
 const persisted = [];
 const store = context({ answers: {}, save: async (...args) => { if (args[3] === 'broken') throw new Error('Field-specific failure'); persisted.push(args[3]); } });
 store.setField('custom-safety', 'broken', 'a');
 store.setField('custom-safety', 'independent', 'b');
 await new Promise(resolve => setImmediate(resolve));
 assert.deepEqual(persisted, ['independent']);
});

test('a write that overlaps a failed section reset waits for it, while other sections still save', async () => {
 let fail = true;
 const events = [];
 const store = context({ answers: { 'custom-safety': { condition: 'none' }, other: {} },
  execute: async () => { if (fail) throw new Error('Local delete unavailable'); events.push('reset'); },
  save: async (...args) => { events.push(args[2] + '.' + args[3]); },
 });
 store.resetSection('custom-safety');
 store.setField('custom-safety', 'condition', 'urgent');
 store.setField('other', 'note', 'x');
 await new Promise(resolve => setImmediate(resolve));
 assert.deepEqual(events, ['other.note']);
 fail = false;
 store.retrySave();
 await new Promise(resolve => setImmediate(resolve));
 assert.deepEqual(events, ['other.note', 'reset', 'custom-safety.condition']);
});

test('an explicit retry that fails again is reported again', async () => {
 const store = context({ answers: {}, save: async () => { throw new Error('Local storage unavailable'); } });
 store.setField('custom-safety', 'first', 'a');
 await new Promise(resolve => setImmediate(resolve));
 const alerts = () => store.writes.filter(row => row[0] === 'Intake niet opgeslagen').length;
 assert.equal(alerts(), 1);
 store.retrySave();
 await new Promise(resolve => setImmediate(resolve));
 assert.equal(alerts(), 2);
});
