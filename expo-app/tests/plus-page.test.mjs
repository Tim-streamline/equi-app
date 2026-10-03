import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';
const content = JSON.parse(await readFile(new URL('../../backend/resources/plus/content.json', import.meta.url), 'utf8'));
const code = ts.transpileModule(await readFile(new URL('../components/plus/PlusPage.tsx', import.meta.url), 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } }).outputText;
const flatten = node => Array.isArray(node) ? node.flatMap(flatten) : node && typeof node === 'object' ? [node, ...(node.type === 'Modal' && !node.props.visible ? [] : flatten(node.props?.children))] : [node];
function screen() {
  const exports = {}, state = []; let cursor = 0, intakes = 0;
  const jsx = (type, props) => ({ type, props });
  const modules = {
    '@/constants/brand': { BRAND_NAME: 'EquiApp' },
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: { useState: value => { const i = cursor++; if (!(i in state)) state[i] = value; return [state[i], next => state[i] = next]; } },
    'react-native': { Modal: 'Modal', Pressable: 'Pressable', ScrollView: 'ScrollView', Text: 'Text', View: 'View', useWindowDimensions: () => ({ width: 375, height: 667 }) },
    'expo-image': { Image: 'Image' }, 'react-native-safe-area-context': { useSafeAreaInsets: () => ({ top: 20, bottom: 20 }) },
    'lucide-react-native': { Check: 'Check', Minus: 'Minus', Plus: 'Plus', X: 'X' }, '@/components/ui/Button': { Button: 'Button' },
  };
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  const render = () => { cursor = 0; return flatten(exports.PlusPage({ data: { content, heroImageUrl: '/api/plus-page/images/hero', portraitImageUrl: null }, apiBaseUrl: 'https://example.test', onIntake: () => intakes++ })); };
  const press = title => { const button = render().find(n => n?.type === 'Button' && n.props.title === title); assert.ok(button, title); button.props.onPress(); };
  return { render, press, get intakes() { return intakes; } };
}
test('all three CTAs share the sheet; dismiss/reopen resets and only final action opens intake', () => {
  const page = screen();
  for (const label of [content.heroButton, content.stepsButton, content.priceButton]) {
    const before = page.intakes;
    page.press(label); assert.ok(page.render().includes('BEVESTIG JE UPGRADE')); assert.equal(page.intakes, before);
    page.press('Nog even niet'); assert.ok(!page.render().includes('BEVESTIG JE UPGRADE'));
    page.press(label); page.press(content.confirmationButton); assert.ok(page.render().includes(content.welcomeTitle)); assert.equal(page.intakes, before);
    page.press('Naar de intake'); assert.ok(!page.render().includes(content.welcomeTitle));
  }
  assert.equal(page.intakes, 3);
});
test('first FAQ starts expanded and questions toggle without stale answers', () => {
  const page = screen(); assert.ok(page.render().includes(content.faqs[0].answer)); assert.ok(!page.render().includes(content.faqs[1].answer));
  const questions = () => page.render().filter(n => n?.type === 'Pressable' && n.props.accessibilityState);
  questions()[1].props.onPress(); assert.ok(page.render().includes(content.faqs[1].answer)); assert.ok(!page.render().includes(content.faqs[0].answer));
  assert.equal(questions()[1].props.accessibilityState.expanded, true);
  questions()[1].props.onPress(); assert.ok(!page.render().includes(content.faqs[1].answer));
});
test('supplied content, shallow hero, horizontal reviews and no-activation disclosure render', () => {
  const page = screen(); const tree = page.render();
  assert.equal(tree.find(n => n?.type === 'Image').props.style.height, 140);
  assert.ok(tree.includes(content.heroTitle)); assert.ok(tree.includes('Voorbeeldreviews'));
  assert.ok(tree.some(n => n?.type === 'ScrollView' && n.props.horizontal));
  for (const review of content.reviews) assert.ok(tree.includes(review.name));
  page.press(content.heroButton); assert.ok(page.render().includes('Je start hiermee je intake. Je abonnement wordt nog niet geactiveerd.'));
});

test('pricing CTA uses the dedicated light button without conflicting text classes', () => {
  const button = screen().render().find(n => n?.type === 'Button' && n.props.title === content.priceButton);
  assert.equal(button.props.variant, 'light');
  assert.equal(button.props.textClassName, undefined);
});
