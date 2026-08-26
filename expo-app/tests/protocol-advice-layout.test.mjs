import assert from 'node:assert/strict';
import test from 'node:test';

import { adviceLayout, advicePresentationSections, groupProtocolAdvice } from '../lib/protocol-advice.ts';

test('keeps the four supported nutrition layouts and falls back safely', () => {
  assert.equal(adviceLayout('normal'), 'normal');
  assert.equal(adviceLayout('link_to_library'), 'link_to_library');
  assert.equal(adviceLayout('roughage'), 'roughage');
  assert.equal(adviceLayout('supplementary_feed'), 'supplementary_feed');
  assert.equal(adviceLayout('unknown'), 'normal');
});

test('groups synced protocol advice by its mobile tab', () => {
  assert.deepEqual(groupProtocolAdvice({
    voeding: [{ id: 'v1', title: 'Ruwvoer', layout: 'roughage' }],
    management: [{ id: 'm1', title: 'Routine', layout: 'normal' }],
    beweging: [{ id: 'b1', title: 'Stappen', layout: 'normal' }],
  }).map((category) => [category.key, category.items.map((item) => item.id)]), [
    ['voeding', ['v1']],
    ['management', ['m1']],
    ['beweging', ['b1']],
  ]);
});

test('orders nutrition layouts and groups supplementary feed into one section', () => {
  const sections = advicePresentationSections([
    { id: 'normal', title: 'Water', layout: 'normal' },
    { id: 'feed-1', title: 'Balancer', layout: 'supplementary_feed' },
    { id: 'library', title: 'Hooi analyseren', layout: 'link_to_library' },
    { id: 'roughage', title: 'Onbeperkt hooi', layout: 'roughage' },
    { id: 'feed-2', title: 'Heucobs', layout: 'supplementary_feed' },
  ]);

  assert.deepEqual(sections.map((section) => [section.layout, section.items.map((item) => item.id)]), [
    ['roughage', ['roughage']],
    ['link_to_library', ['library']],
    ['supplementary_feed', ['feed-1', 'feed-2']],
    ['normal', ['normal']],
  ]);
});
