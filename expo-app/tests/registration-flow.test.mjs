import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';

async function screen(file, { db = {}, mutations = {}, platform = 'android', auth = {} } = {}) {
  const source = await readFile(new URL(`../app/onboarding/${file}.tsx`, import.meta.url), 'utf8');
  const { outputText } = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } });
  const state = [], refs = [], routes = [], effects = [], queuedEffects = [];
  const timers = new Map(); let nextTimer = 0;
  const waitSource = await readFile(new URL('../lib/registration-wait.ts', import.meta.url), 'utf8');
  const waitModule = {};
  new Function('exports','setTimeout','clearTimeout',ts.transpileModule(waitSource,{compilerOptions:{module:ts.ModuleKind.CommonJS}}).outputText)(waitModule, callback => { const id=++nextTimer; timers.set(id,callback); return id; }, id=>timers.delete(id));
  let stateIndex = 0, refIndex = 0, effectIndex = 0;
  const jsx = (type, props) => ({ type, props });
  const modules = {
    '@/components/ui/KeyboardForm': { KeyboardViewport: 'KeyboardAvoidingView', KeyboardScrollView: 'ScrollView', KeyboardTextInput: 'TextInput' },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    react: {
      useState: initial => { const i = stateIndex++; if (!(i in state)) state[i] = initial; return [state[i], value => { state[i] = value; }]; },
      useEffect: (callback,deps) => { const i=effectIndex++; const old=effects[i]; if(!old || deps.some((d,j)=>!Object.is(d,old.deps[j]))) { queuedEffects.push(()=>{old?.cleanup?.(); effects[i]={deps,cleanup:callback()};}); } },
      useRef: initial => { const i = refIndex++; return refs[i] ??= { current: initial }; },
    },
    'react-native': { ...Object.fromEntries(['ActivityIndicator', 'KeyboardAvoidingView', 'ScrollView', 'Text', 'View'].map(name => [name,name])), Platform: { OS: platform } },
    'expo-router': { Redirect: 'Redirect', router: { replace: path => routes.push(path), back: () => routes.push('back') } },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    '@/components/ui/SubHeader': { SubHeader: 'SubHeader' },
    '@/components/ui/Field': { Field: 'Field' },
    '@/components/ui/Button': { Button: 'Button' },
    '@/db/hooks': { useStoreMutations: () => mutations },
    '@/db/provider': { useDb: () => db },
    '@/db/auth': auth,
    '@/lib/registration-wait': waitModule,
  };
  const exports = {};
  new Function('require', 'exports', outputText)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  const render = () => { stateIndex = 0; refIndex = 0; effectIndex = 0; const result=exports.default(); while(queuedEffects.length) queuedEffects.shift()(); return result; };
  const settle = async () => { await new Promise(resolve=>setImmediate(resolve)); render(); await new Promise(resolve=>setImmediate(resolve)); };
  const find = (node, predicate) => {
    if (!node || typeof node !== 'object') return;
    if (predicate(node)) return node;
    return [node.props?.children].flat(Infinity).map(child => find(child,predicate)).find(Boolean);
  };
  return {
    fill: (label,value) => find(render(),node => node.type==='Field' && node.props.accessibilityLabel===label).props.onChangeText(value),
    press: async title => { const action = find(render(),node => node.type==='Button' && node.props.title===title); assert.ok(action,title); action.props.onPress(); await settle(); },
    error: () => find(render(),node => node.props?.accessibilityRole==='alert')?.props.children,
    tick: async () => { const next=timers.entries().next().value; assert.ok(next,'a status poll is scheduled'); timers.delete(next[0]); next[1](); await settle(); },
    dispose: () => { effects.forEach(effect=>effect?.cleanup?.()); },
    hasField: label => !!find(render(),node=>node.type==='Field' && node.props.accessibilityLabel===label),
    timerCount: () => timers.size,
    settle, render, routes,
  };
}

test('registration waits for the emailed link and automatically logs in without a code', async () => {
  const calls=[], completions=[];
  let confirmed=false;
  const page=await screen('register',{
    db:{register:async input=>{calls.push(input); return {registration_token:'challenge',email:input.email};},completeRegistration:async(...args)=>completions.push(args)},
    auth:{checkRegistration:async()=>({status:confirmed?'confirmed':'pending'})},
  });
  page.fill('Naam',' Owner '); page.fill('E-mailadres','owner@example.test');
  page.fill('Wachtwoord','new-password'); page.fill('Herhaal wachtwoord','mismatch');
  await page.press('Account aanmaken'); assert.equal(calls.length,0); assert.match(page.error(),/niet overeen/);
  page.fill('Herhaal wachtwoord','new-password'); await page.press('Account aanmaken');
  assert.equal(calls[0].name,'Owner'); assert.deepEqual(page.routes,[]);
  assert.equal(page.hasField('Bevestigingscode'),false); assert.equal(completions.length,0);
  confirmed=true; await page.tick();
  assert.deepEqual(completions,[[{registration_token:'challenge'},'new-password']]);
  assert.deepEqual(page.routes,['/onboarding/add-horse']); assert.equal(page.timerCount(),0);
  page.dispose();
});

test('failed registration stays on the form and displays the server error', async () => {
  const page=await screen('register',{db:{register:async()=>{throw new Error('E-mailadres al in gebruik');}}});
  for(const [label,value] of [['Naam','Owner'],['E-mailadres','owner@example.test'],['Wachtwoord','password'],['Herhaal wachtwoord','password']]) page.fill(label,value);
  await page.press('Account aanmaken'); assert.deepEqual(page.routes,[]); assert.match(page.error(),/al in gebruik/);
});

test('skipping horse creation performs no writes and goes to Home', async () => {
  let writes=0;
  const page=await screen('add-horse',{db:{isLoggedIn:true,currentUserId:'new-owner'},mutations:{upsertHorse:async()=>{writes++;}}});
  await page.press('Nu overslaan'); assert.equal(writes,0); assert.deepEqual(page.routes,['/(tabs)/(pager)/home']);
});

test('adding a horse creates a fresh row for the authenticated owner and selects it', async () => {
  const calls=[], selected=[];
  const page=await screen('add-horse',{db:{isLoggedIn:true,currentUserId:'new-owner',selectedHorseId:'existing-horse',selectHorse:id=>selected.push(id)},mutations:{upsertHorse:async(id,input)=>{calls.push({id,input});return 'new-horse';}}});
  await page.press('Paard toevoegen'); assert.equal(calls.length,0); assert.match(page.error(),/naam/);
  page.fill('Naam van je paard',' Nova '); await page.press('Paard toevoegen');
  assert.equal(calls[0].id,''); assert.equal(calls[0].input.ownerId,'new-owner'); assert.equal(calls[0].input.name,'Nova');
  assert.equal(calls[0].input.age,null); assert.equal(calls[0].input.weightKg,null);
  assert.deepEqual(selected,['new-horse']); assert.deepEqual(page.routes,['/(tabs)/(pager)/home']);
});

test('failed horse persistence stays on the form and allows retry', async () => {
  const page=await screen('add-horse',{db:{isLoggedIn:true,currentUserId:'new-owner'},mutations:{upsertHorse:async()=>{throw new Error('disk failure');}}});
  page.fill('Naam van je paard','Nova'); await page.press('Paard toevoegen');
  assert.deepEqual(page.routes,[]); assert.match(page.error(),/niet worden opgeslagen/);
});



const fillAccount = page => {
  for(const [label,value] of [['Naam','Owner'],['E-mailadres','owner@example.test'],['Wachtwoord','password'],['Herhaal wachtwoord','password']]) page.fill(label,value);
};

test('expiry stops polling and resending waits on a fresh registration token', async () => {
  let sent=0;
  const completed=[], checked=[];
  const page=await screen('register',{db:{
    register:async input=>({registration_token:`challenge-${++sent}`,email:input.email}),
    completeRegistration:async input=>completed.push(input),
  },auth:{checkRegistration:async input=>{checked.push(input); return {status:input.registration_token==='challenge-1'?'expired':'confirmed'};}}});
  fillAccount(page); await page.press('Account aanmaken');
  assert.match(page.error(),/verlopen/); assert.deepEqual(page.routes,[]); assert.equal(page.timerCount(),0);
  await page.press('Bevestigingsmail opnieuw versturen');
  assert.equal(completed[0].registration_token,'challenge-2');
  assert.deepEqual(checked.map(c=>c.registration_token),['challenge-1','challenge-2']);
  page.dispose();
});

test('transient status and login failures retry automatically', async () => {
  let checks=0, finishes=0;
  const page=await screen('register',{db:{
    register:async input=>({registration_token:'challenge',email:input.email}),
    completeRegistration:async()=>{if(++finishes===1) throw new Error('Verbinding verbroken');},
  },auth:{checkRegistration:async()=>{if(++checks===1) throw new Error('Tijdelijk offline'); return {status:'confirmed'};}}});
  fillAccount(page); await page.press('Account aanmaken'); assert.match(page.error(),/offline/);
  await page.tick(); assert.match(page.error(),/Verbinding/); assert.deepEqual(page.routes,[]);
  await page.tick(); assert.deepEqual(page.routes,['/onboarding/add-horse']); assert.equal(finishes,2);
  page.dispose();
});

test('closing the waiting screen ignores a late confirmation response', async () => {
  let resolve, completed=0;
  const page=await screen('register',{db:{register:async input=>({registration_token:'challenge',email:input.email}),completeRegistration:async()=>completed++},auth:{checkRegistration:async()=>new Promise(r=>{resolve=r;})}});
  fillAccount(page); await page.press('Account aanmaken'); page.dispose();
  resolve({status:'confirmed'}); await page.settle();
  assert.equal(completed,0); assert.deepEqual(page.routes,[]); assert.equal(page.timerCount(),0);
});

test('changing the email cancels the previous waiting request', async () => {
  let resolve, completed=0;
  const page=await screen('register',{db:{register:async input=>({registration_token:'challenge',email:input.email}),completeRegistration:async()=>completed++},auth:{checkRegistration:async()=>new Promise(r=>{resolve=r;})}});
  fillAccount(page); await page.press('Account aanmaken');
  await page.press('E-mailadres wijzigen'); page.fill('E-mailadres','correct@example.test');
  resolve({status:'confirmed'}); await page.settle();
  assert.equal(completed,0); assert.deepEqual(page.routes,[]); assert.equal(page.hasField('E-mailadres'),true);
  page.dispose();
});

test('web waiting screen leaves successful navigation to the browser session provider', async () => {
  let completed=0;
  const page=await screen('register',{platform:'web',db:{register:async input=>({registration_token:'challenge',email:input.email}),completeRegistration:async()=>completed++},auth:{checkRegistration:async()=>({status:'confirmed'})}});
  fillAccount(page); await page.press('Account aanmaken');
  assert.equal(completed,1); assert.deepEqual(page.routes,[]); page.dispose();
});


test('a failed resend shows the error and resumes polling the previous valid link', async () => {
  let sends=0, confirmed=false, completed=0;
  const page=await screen('register',{db:{
    register:async input=>{if(++sends>1) throw new Error('Wacht een minuut'); return {registration_token:'original',email:input.email};},
    completeRegistration:async()=>completed++,
  },auth:{checkRegistration:async()=>({status:confirmed?'confirmed':'pending'})}});
  fillAccount(page); await page.press('Account aanmaken');
  await page.press('Bevestigingsmail opnieuw versturen'); assert.match(page.error(),/Wacht een minuut/);
  confirmed=true; await page.tick(); assert.equal(completed,1); assert.deepEqual(page.routes,['/onboarding/add-horse']);
  page.dispose();
});
