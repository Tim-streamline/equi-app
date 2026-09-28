import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import ts from 'typescript';

async function screen(file, { db = {}, mutations = {}, platform = 'android' } = {}) {
  const source = await readFile(new URL(`../app/onboarding/${file}.tsx`, import.meta.url), 'utf8');
  const { outputText } = ts.transpileModule(source, { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } });
  const state = [], refs = [], routes = [];
  let stateIndex = 0, refIndex = 0;
  const jsx = (type, props) => ({ type, props });
  const modules = {
    'react/jsx-runtime': { jsx, jsxs: jsx },
    react: {
      useState: initial => { const i = stateIndex++; if (!(i in state)) state[i] = initial; return [state[i], value => { state[i] = value; }]; },
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
  };
  const exports = {};
  new Function('require', 'exports', outputText)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  const render = () => { stateIndex = 0; refIndex = 0; return exports.default(); };
  const find = (node, predicate) => {
    if (!node || typeof node !== 'object') return;
    if (predicate(node)) return node;
    return [node.props?.children].flat(Infinity).map(child => find(child,predicate)).find(Boolean);
  };
  return {
    fill: (label,value) => find(render(),node => node.type==='Field' && node.props.accessibilityLabel===label).props.onChangeText(value),
    press: async title => { const action = find(render(),node => node.type==='Button' && node.props.title===title); assert.ok(action,title); action.props.onPress(); await new Promise(resolve => setImmediate(resolve)); },
    error: () => find(render(),node => node.props?.accessibilityRole==='alert')?.props.children,
    render, routes,
  };
}

test('registration validates confirmation then opens the optional horse step', async () => {
  const calls=[];
  const page=await screen('register',{db:{register:async input=>calls.push(input)}});
  page.fill('Naam',' Owner '); page.fill('E-mailadres','owner@example.test');
  page.fill('Wachtwoord','new-password'); page.fill('Herhaal wachtwoord','mismatch');
  await page.press('Account aanmaken'); assert.equal(calls.length,0); assert.match(page.error(),/niet overeen/);
  page.fill('Herhaal wachtwoord','new-password'); await page.press('Account aanmaken');
  assert.equal(calls[0].name,'Owner'); assert.deepEqual(page.routes,['/onboarding/add-horse']);
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
