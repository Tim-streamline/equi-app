import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { pressableClassName, textClassName } from '../lib/ui-defaults.ts';

test('root text gets the brand font unless it sets its own; nested text keeps inheriting', () => {
  assert.equal(textClassName(undefined, false, false), 'font-sans');
  assert.equal(textClassName('text-[14px] text-ink-70', false, false), 'font-sans text-[14px] text-ink-70');
  for (const font of ['font-sans', 'font-medium', 'font-semi', 'font-bold', 'font-italic', 'font-semi-italic']) {
    assert.equal(textClassName(`${font} text-ink`, false, false), `${font} text-ink`);
  }
  assert.equal(textClassName('text-ink', true, false), 'text-ink', 'a style fontFamily wins');
  assert.equal(textClassName('text-mint-300', false, true), 'text-mint-300', 'nested text inherits the parent font');
  assert.equal(textClassName('font-sansx', false, false), 'font-sans font-sansx');
});

test('pressables that act get a pressed state unless they handle it themselves', () => {
  const onPress = () => {};
  assert.equal(pressableClassName('rounded-xl p-4', { onPress }), 'rounded-xl p-4 active:opacity-70');
  assert.equal(pressableClassName(undefined, { onLongPress: onPress }), 'active:opacity-70');
  assert.equal(pressableClassName('p-4', {}), 'p-4', 'nothing to press');
  assert.equal(pressableClassName('p-4', { onPress, disabled: true }), 'p-4');
  assert.equal(pressableClassName('bg-mint-500 active:bg-mint-400', { onPress }), 'bg-mint-500 active:bg-mint-400');
  assert.equal(pressableClassName('flex-1 active:opacity-100', { onPress }), 'flex-1 active:opacity-100', 'explicit opt-out');
  assert.equal(pressableClassName(undefined, { onPress, style: () => ({}) }), undefined, 'style callbacks own their feedback');
});

test('Metro sends only app source react-native imports through the defaults module', () => {
  for (const config of ['../metro.config.js', '../../web/metro.config.js']) {
    const source = readFileSync(new URL(config, import.meta.url), 'utf8');
    assert.match(source, /components\/react-native-defaults/);
    assert.match(source, /path\.dirname\(origin\) !== uiDefaultsDir/, 'the defaults module must not resolve to itself');
    assert.match(source, /node_modules/);
  }
});
