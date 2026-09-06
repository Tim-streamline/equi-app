import assert from 'node:assert/strict';
import test from 'node:test';
import { careSections } from '../lib/protocol-care.ts';
import { PROTOCOL_TABS, protocolTab } from '../lib/protocol-tabs.ts';

test('combines selected advice in the four care sections without losing personal instructions', () => {
  const environment = { id: 'e', title: 'Weidegang', description: 'Geen weidegang tijdens herstel.', action: 'avoid', note: 'Tot de evaluatie.', frequency: 'Tijdens dit protocol', url: 'https://example.com/weide', ctaLabel: 'Toelichting' };
  const movement = { id: 'm', title: 'Rustig stappen', description: '10 minuten, tweemaal per dag. Niet draven.' };
  const physical = { id: 'p', title: 'Hoefverzorging', description: 'Persoonlijke verzorging.' };
  const research = { id: 'r', title: 'Mestonderzoek', description: 'Persoonlijk onderzoek.' };
  const protocol = {
    management: [
      { id: 'monitoring', items: [research] },
      { id: 'care', items: [physical] },
      { id: 'environment', items: [environment] },
    ],
    movement: [movement],
    analysis: { summary: 'Analyse blijft apart' },
  };
  assert.deepEqual(careSections(protocol), [
    { id: 'environment', title: 'Leefomgeving & weide', items: [environment] },
    { id: 'movement', title: 'Beweging & belasting', items: [movement] },
    { id: 'care', title: 'Lichamelijke zorg', items: [physical] },
    { id: 'monitoring', title: 'Onderzoek', items: [research] },
  ]);
});

test('omits empty sections, keeps every movement advice, and never creates example advice', () => {
  assert.deepEqual(careSections({ management: [], movement: [] }), []);
  const movement = [{ id: 'one', title: 'Duur' }, { id: 'two', title: 'Beperking' }];
  assert.deepEqual(careSections({ management: [{ id: 'care', items: [] }], movement }), [
    { id: 'movement', title: 'Beweging & belasting', items: movement },
  ]);
});

test('uses the managed category even when the advice title suggests another section', () => {
  const item = { id: 'custom', title: 'Bloedonderzoek', description: 'Door de therapeut anders ingedeeld.' };
  assert.deepEqual(careSections({ management: [{ id: 'care', items: [item] }], movement: [] }), [
    { id: 'care', title: 'Lichamelijke zorg', items: [item] },
  ]);
});

test('offers exactly five tabs and preserves incoming links to the two merged tabs', () => {
  assert.deepEqual(PROTOCOL_TABS.map(({ label }) => label), ['Vandaag', 'Kalender', 'Voeding', 'Zorg', 'Analyse']);
  for (const { key } of PROTOCOL_TABS) assert.equal(protocolTab(key), key);
  assert.equal(protocolTab('management'), 'zorg');
  assert.equal(protocolTab('beweging'), 'zorg');
  assert.equal(protocolTab('unknown'), undefined);
  assert.equal(protocolTab(undefined), undefined);
});
