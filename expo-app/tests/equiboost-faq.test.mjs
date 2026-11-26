import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const content = JSON.parse(await readFile(new URL('../../backend/resources/plus/content.json', import.meta.url), 'utf8'));
const code = ts.transpileModule(await readFile(new URL('../app/equiboost-faq.tsx', import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const questionsCode = ts.transpileModule(await readFile(new URL('../components/protocol/ProtocolQuestions.tsx', import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...flatten(node.props?.children)] : [node];
const jsx = (type, props) => ({ type, props });
const texts = nodes => nodes.filter(node => typeof node === 'string');

function screen(page) {
  const exports = {}, state = [], routes = []; let cursor = 0;
  const modules = {
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: { useState: value => { const i = cursor++; if (!(i in state)) state[i] = value; return [state[i], next => { state[i] = typeof next === 'function' ? next(state[i]) : next; }]; }, useCallback: fn => fn },
    'react-native': { ActivityIndicator: 'ActivityIndicator', Pressable: 'Pressable', ScrollView: 'ScrollView', Text: 'Text', View: 'View' },
    'expo-router': { router: { push: route => routes.push(route), back() {}, canGoBack: () => true, replace() {} }, useFocusEffect() {} },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'lucide-react-native': { Minus: 'Minus', Play: 'Play', Plus: 'Plus' },
    '@/db/auth': { getApiBaseUrl: () => 'https://example.test' },
    '@/components/ui/SubHeader': { SubHeader: 'SubHeader' }, '@/components/ui/Button': { Button: 'Button' },
    '@/components/ui/styles': { PLAIN_CARD: 'card' },
    '@/components/protocol/styles': { PROTOCOL_TEXT: new Proxy({}, { get: () => '' }) },
    '@/lib/horse-dashboard': { libraryPath: item => ({ pathname: `/(tabs)/library/${item.format}/[id]`, params: { id: item.id } }) },
  };
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  // The first state is the loaded page; the test supplies it directly instead of fetching.
  state[0] = page;
  const render = () => { cursor = 0; return flatten(exports.default()); };
  return { render, routes };
}

test('the FAQ shows the video, opens one answer at a time and ends with Shelby', () => {
  const page = screen({ content, memberFaqVideo: { id: 'video-1', format: 'video', title: 'Veelgestelde vragen tijdens EquiBoost' } });
  const nodes = page.render();
  assert.ok(texts(nodes).includes(content.memberFaqTitle));
  for (const faq of content.memberFaqs) assert.ok(texts(nodes).includes(faq.question));
  assert.ok(!texts(nodes).includes(content.memberFaqs[0].answer), 'answers start closed');

  page.render().find(node => node?.props?.accessibilityLabel?.startsWith('Bekijk de video')).props.onPress();
  assert.deepEqual(page.routes.at(-1), { pathname: '/(tabs)/library/video/[id]', params: { id: 'video-1' } });

  const questions = () => page.render().filter(node => node?.type === 'Pressable' && node.props.accessibilityState);
  questions()[2].props.onPress();
  assert.ok(texts(page.render()).includes(content.memberFaqs[2].answer));
  assert.equal(questions()[2].props.accessibilityState.expanded, true);
  questions()[3].props.onPress();
  assert.ok(!texts(page.render()).includes(content.memberFaqs[2].answer));

  page.render().find(node => node?.type === 'Button' && node.props.title === 'Vraag het Shelby').props.onPress();
  assert.equal(page.routes.at(-1), '/nova-chat');
});

test('without a published video the FAQ still works', () => {
  const nodes = screen({ content, memberFaqVideo: null }).render();
  assert.ok(!nodes.some(node => node?.props?.accessibilityLabel?.startsWith('Bekijk de video')));
  assert.ok(texts(nodes).includes(content.memberFaqs[0].question));
});

test('the line under each protocol tab links to the FAQ and to Shelby', () => {
  const exports = {}, pressed = [];
  new Function('require', 'exports', questionsCode)(name => ({
    'react/jsx-runtime': { jsx, jsxs: jsx }, 'react-native': { Pressable: 'Pressable', Text: 'Text', View: 'View' },
    '@/components/protocol/styles': { PROTOCOL_SCALE: { label: '', secondary: '' } },
  })[name], exports);
  const nodes = flatten(exports.ProtocolQuestions({ onFaq: () => pressed.push('faq'), onShelby: () => pressed.push('shelby') }));
  assert.ok(texts(nodes).includes('Vragen over je EquiBoost traject?'));
  const links = nodes.filter(node => node?.props?.accessibilityRole === 'link');
  assert.deepEqual(links.map(link => texts(flatten(link.props.children))[0]), ['Bekijk veelgestelde vragen', 'vraag het Shelby']);
  links.forEach(link => link.props.onPress());
  assert.deepEqual(pressed, ['faq', 'shelby']);
});
