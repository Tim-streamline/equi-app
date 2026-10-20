import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/Pages/Library/Index.svelte', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^\s*import .*;$/gm, '');

function page(orderNeighbors) {
    const calls = [];
    const context = vm.createContext({
        $props: () => ({ items: { data: [{ id: 'first' }, { id: 'last' }] },
            filters: {}, counts: {}, categories: [], orderNeighbors }),
        $state: value => value, $derived: value => value, $effect() {}, onMount() {},
        router: { patch: (...args) => calls.push(args) },
    });
    vm.runInContext(script, context);
    return { calls, run: expression => vm.runInContext(expression, context) };
}

test('boundary arrows submit the neighboring ID from the adjacent page', () => {
    const view = page({ previous: 'previous-page-last', next: 'next-page-first' });
    assert.equal(view.run("neighborId(0, 'up')"), 'previous-page-last');
    assert.equal(view.run("neighborId(1, 'down')"), 'next-page-first');
    view.run("move(items.data[0], 0, 'up')");
    assert.equal(view.calls[0][0], '/admin/library/first/move');
    assert.equal(view.calls[0][1].neighbor_id, 'previous-page-last');
    assert.equal(view.calls[0][1].direction, 'up');
    assert.equal(view.calls[0][2].preserveScroll, true);

    view.run("move(items.data[1], 1, 'down')");
    assert.equal(view.calls.length, 1, 'A pending move prevents another request.');
    view.calls[0][2].onFinish();
    view.run("move(items.data[1], 1, 'down')");
    assert.equal(view.calls[1][0], '/admin/library/last/move');
    assert.equal(view.calls[1][1].neighbor_id, 'next-page-first');
    assert.equal(view.calls[1][1].direction, 'down');
});

test('arrows stop only at the ends of all filtered results and retain within-page neighbors', () => {
    const view = page({ previous: null, next: null });
    assert.equal(view.run("neighborId(0, 'up')"), null);
    assert.equal(view.run("neighborId(1, 'down')"), null);
    view.run("move(items.data[0], 0, 'up'); move(items.data[1], 1, 'down')");
    assert.equal(view.calls.length, 0);
    assert.equal(view.run("neighborId(0, 'down')"), 'last');
    assert.equal(view.run("neighborId(1, 'up')"), 'first');
    view.run("move(items.data[0], 0, 'down')");
    assert.equal(view.calls[0][1].neighbor_id, 'last');
});
