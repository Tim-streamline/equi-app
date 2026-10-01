import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../app/onboarding/welcome.tsx', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
});

// Inspect the rendered layout contract without loading native modules in Node.
// Actual keyboard visibility is verified separately on Android.
function renderLogin(platform, params = {}, replace = () => {}, push = () => {}) {
  const jsx = (type, props) => ({ type, props });
  const modules = {
    '@/components/ui/KeyboardForm': { KeyboardViewport: 'KeyboardAvoidingView', KeyboardScrollView: 'ScrollView', KeyboardTextInput: 'TextInput' },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    react: { useState: (initial) => [initial, () => {}] },
    'react-native': {
      ...Object.fromEntries([
        'View', 'Text', 'Image', 'TextInput', 'ActivityIndicator', 'Pressable',
        'KeyboardAvoidingView', 'ScrollView',
      ].map((name) => [name, name])),
      Platform: { OS: platform },
    },
    'expo-router': { router: { replace, push }, useLocalSearchParams: () => params },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'expo-status-bar': { StatusBar: 'StatusBar' },
    'lucide-react-native': { ArrowRight: 'ArrowRight' },
    '@/components/ui/Button': { Button: 'Button' },
    '@/db/provider': { useDb: () => ({ login: async () => {} }) },
    '@/assets/images/logo-horse-white.png': 'horse-logo',
  };
  const exports = {};
  new Function('require', 'exports', outputText)((name) => {
    assert.ok(name in modules, `Unexpected dependency: ${name}`);
    return modules[name];
  }, exports);
  return exports.default();
}

test('signing in from a shared Community discussion returns to that discussion', async () => {
  const id = 'c6812914-557c-4ee0-abd6-25827b3dc83e';
  let destination;
  const login = renderLogin('android', { communityPost: id }, route => { destination = route; });
  await find(login, 'Button').props.onPress();
  assert.deepEqual(destination, { pathname: '/(tabs)/community/thread/[id]', params: { id } });
});

function find(node, type, matches = () => true) {
  if (!node || typeof node !== 'object') return undefined;
  if (node.type === type && matches(node.props)) return node;
  const children = [node.props?.children].flat(Infinity);
  return children.map((child) => find(child, type, matches)).find(Boolean);
}

for (const [platform, behavior] of [['android', 'height'], ['ios', 'padding']]) {
  test(`${platform}: the entire login form avoids the keyboard and can scroll`, () => {
    const avoiding = find(renderLogin(platform), 'KeyboardAvoidingView');
    assert.ok(avoiding, 'The login screen must avoid the software keyboard');
    assert.equal(avoiding.props.behavior, behavior);
    assert.equal(avoiding.props.style.flex, 1);

    // Avoidance starts outside the safe area so keyboard screen coordinates
    // are not measured against a status-bar-offset inner view.
    const safeArea = find(avoiding, 'SafeAreaView');
    const scroll = find(safeArea, 'ScrollView');
    assert.ok(scroll, 'The form must scroll on short screens or with large text');
    assert.equal(scroll.props.contentContainerStyle.flexGrow, 1);
    assert.equal(scroll.props.contentContainerStyle.flex, undefined);
    assert.equal(scroll.props.contentContainerStyle.height, undefined);
    assert.equal(scroll.props.contentContainerStyle.justifyContent, 'space-between');
    assert.equal(scroll.props.keyboardShouldPersistTaps, 'handled');
    assert.ok(
      find(scroll, 'TextInput', (props) => props.secureTextEntry),
      'The password field must be inside the scrollable area',
    );
    assert.ok(find(scroll, 'Button'), 'Login must remain reachable with the keyboard open');
  });
}


test('password recovery is reachable from the login screen and email uses the supplied placeholder', () => {
  let destination;
  const screen = renderLogin('web', {}, () => {}, route => { destination = route; });
  assert.equal(find(screen, 'TextInput', props => props.autoComplete === 'email').props.placeholder, 'e-mailadres');
  const link = find(screen, 'Pressable', props => props.accessibilityRole === 'link');
  assert.equal(find(link, 'Text').props.children, 'Wachtwoord vergeten?');
  link.props.onPress();
  assert.equal(destination, '/onboarding/forgot-password');
});
