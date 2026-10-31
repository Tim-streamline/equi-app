import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

// Load only pageFade; its interpolate() calls are recorded instead of animated.
const source = readFileSync(new URL('../components/ui/AppHeader.tsx', import.meta.url), 'utf8');
const body = source.slice(source.indexOf('const SLIDE'), source.indexOf('/** One page'));
const code = ts.transpileModule(body + '\nexports.pageFade = pageFade;', { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
const exports = {};
new Function('exports', code)(exports);
const position = { interpolate: config => config };
const opacityAt = (style, at) => {
  if (!style.opacity?.inputRange) return style.opacity;
  const { inputRange: [a, b], outputRange: [x, y] } = style.opacity;
  const t = Math.min(1, Math.max(0, (at - a) / (b - a)));
  return x + (y - x) * t;
};

test('jumping several tabs only shows the old and the new page, never the ones in between', () => {
  for (const [from, to] of [[0, 3], [3, 0], [1, 2]]) {
    const styles = [0, 1, 2, 3].map(page => exports.pageFade(position, page, from, to));
    for (let at = Math.min(from, to); at <= Math.max(from, to); at += 0.25) {
      for (const page of [0, 1, 2, 3]) {
        const opacity = opacityAt(styles[page], at);
        if (page !== from && page !== to) assert.equal(opacity, 0, `page ${page} at ${at} (${from}→${to})`);
      }
      assert.ok(opacityAt(styles[from], at) === 0 || opacityAt(styles[to], at) === 0, `old and new never overlap at ${at}`);
    }
    assert.equal(opacityAt(styles[from], from), 1);
    assert.equal(opacityAt(styles[to], to), 1);
  }
});

test('a settled page is simply visible', () => {
  assert.deepEqual(exports.pageFade(position, 2, 2, 2), { opacity: 1 });
  assert.deepEqual(exports.pageFade(position, 1, 2, 2), { opacity: 0 });
});
