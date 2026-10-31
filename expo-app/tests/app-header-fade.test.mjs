import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

// Load only the transition maths from AppHeader (no React Native needed).
const source = readFileSync(new URL('../components/ui/AppHeader.tsx', import.meta.url), 'utf8');
const body = source.slice(source.indexOf('const SLIDE'), source.indexOf('/**\n * Native animation graph'));
const code = ts.transpileModule(`${body}\nexports.settled = settled; exports.nextTransition = nextTransition;`, {
  compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText;
const { settled: settledAt, nextTransition } = (() => { const exports = {}; new Function('exports', code)(exports); return exports; })();
const settled = page => settledAt(page, 4);
// What the native graph in AppHeader computes for a page at pager position x.
const opacityAt = (t, page, x) => t.on[page] * Math.min(1, Math.max(0, 1 - Math.abs(x - page) / t.width[page]));
const pages = [0, 1, 2, 3];
const visible = (t, x) => pages.map(page => Number(opacityAt(t, page, x).toFixed(3)));

test('a settled page is fully visible and the others are hidden', () => {
  assert.deepEqual(visible(settled(2), 2), [0, 0, 1, 0]);
});

test('jumping several tabs only shows the old and the new page, never the ones in between', () => {
  for (const [from, to] of [[0, 3], [3, 0], [1, 2], [2, 1]]) {
    const t = nextTransition(settled(from), to, false);
    assert.deepEqual(visible(t, from), visible(settled(from), from), 'nothing changes at the moment of the tap');
    for (let at = Math.min(from, to); at <= Math.max(from, to); at += 0.25) {
      for (const page of pages) if (page !== from && page !== to) assert.equal(opacityAt(t, page, at), 0, `page ${page} at ${at} (${from}→${to})`);
      assert.ok(opacityAt(t, from, at) === 0 || opacityAt(t, to, at) === 0, `old and new never overlap at ${at}`);
    }
    assert.deepEqual(visible(t, to), visible(settled(to), to), 'ends exactly like a settled page');
  }
});

test('a page change that interrupts another never jumps and ends clean', () => {
  // 0 → 2, then page 3 is tapped while the pager is anywhere on the way.
  const first = nextTransition(settled(0), 2, false);
  const second = nextTransition(first, 3, true);
  for (let at = 0; at <= 2; at += 0.1) assert.deepEqual(visible(second, at), visible(first, at), `no visible change when tapped at ${at.toFixed(1)}`);
  for (let at = 0; at <= 3; at += 0.1) assert.equal(opacityAt(second, 1, at), 0, 'a page that was never chosen stays hidden');
  assert.deepEqual(visible(second, 3), [0, 0, 0, 1]);
});

test('later changes leave no ghosts of earlier ones', () => {
  // 0 → 3 completes, then 3 → 1: Home's wide window must not reach page 1.
  const t = nextTransition(nextTransition(settled(0), 3, false), 1, false);
  assert.deepEqual(visible(t, 3), [0, 0, 0, 1]);
  assert.deepEqual(visible(t, 1), [0, 1, 0, 0]);
  // Going back to the page that was leaving continues from where it is.
  const there = nextTransition(settled(0), 1, false);
  const back = nextTransition(there, 0, true);
  for (let at = 0; at <= 1; at += 0.1) assert.equal(opacityAt(back, 0, at).toFixed(3), opacityAt(there, 0, at).toFixed(3));
  assert.deepEqual(visible(back, 0), [1, 0, 0, 0]);
});

test('a quick tap after a finished change still crossfades cleanly without ghosts', () => {
  // Treated as interrupting although the pager already reached page 3.
  const t = nextTransition(nextTransition(settled(0), 3, false), 2, true);
  assert.deepEqual(visible(t, 3), [0, 0, 0, 1]);
  assert.deepEqual(visible(t, 2), [0, 0, 1, 0]);
});

test('a chain of quick taps keeps every title that is still on screen fading, not just the last two', () => {
  // Home → Community, then Protocol, then Bibliotheek, each before the pager comes to rest.
  const toCommunity = nextTransition(settled(0), 3, false);
  const toProtocol = nextTransition(toCommunity, 1, true);
  const toLibrary = nextTransition(toProtocol, 2, true);
  // Wherever the pager is, Home's title (two changes back) is as visible as it was.
  for (let at = 0; at <= 3; at += 0.1) assert.equal(opacityAt(toLibrary, 0, at).toFixed(3), opacityAt(toProtocol, 0, at).toFixed(3), `Home at ${at.toFixed(1)}`);
  assert.deepEqual(visible(toLibrary, 2), [0, 0, 1, 0], 'ends clean');
  // Once the pager has come to rest, the next change starts fresh.
  assert.deepEqual(nextTransition(toLibrary, 0, false).on, [1, 0, 1, 0]);
});
