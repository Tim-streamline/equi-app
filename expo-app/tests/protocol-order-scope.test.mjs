import assert from 'node:assert/strict';
import test from 'node:test';
import { orderQuantityLabel, scopeOrderItems } from '../lib/protocol-order-scope.ts';

const component = (selectionId, phaseId, quantity, unit = 'g') => ({ selectionId, phaseId, quantity, unit,
  dosePerAdministration: quantity === null ? null : 10, administrationsPerDay: 1, daysPerWeek: 7,
  administrationsPerWeek: 7, scheduledWeeks: 2, administrations: 14 });
const herb = { id: 'herb', productKey: 'herb', name: 'Heemstwortel', catalogIds: ['c1'], selectionIds: ['s1', 's2'],
  quantity: 350, unit: 'g', quantityLabel: '350 g', calculationComplete: true, calculationBasis: 'daily_7_per_week',
  purchaseOptions: [{ url: 'https://shop.example/heemst', label: 'Bekijk / bestel product →' }],
  components: [component('s1', 'phase1', 210), component('s2', 'phase2', 140)] };
const phase1Only = { ...herb, id: 'zink', productKey: 'zink', name: 'Zink', selectionIds: ['s3'], quantity: 1400,
  quantityLabel: '1,4 kg', components: [component('s3', 'phase1', 1400)] };
const items = [herb, phase1Only];

test('a phase or notice shows only its own share of a full-protocol product', () => {
  const phase2 = scopeOrderItems(items, { phaseId: 'phase2' });
  assert.deepEqual(phase2.map(item => [item.name, item.quantity, item.quantityLabel, item.selectionIds]),
    [['Heemstwortel', 140, '140 g', ['s2']]]);
  assert.deepEqual(phase2[0].purchaseOptions, herb.purchaseOptions);
  const notice = scopeOrderItems(items, { selections: [{ id: 's2', phaseId: 'phase2' }, { id: 's2', phaseId: 'phase2' }] });
  assert.equal(notice.length, 1);
  assert.equal(notice[0].quantity, 140, 'duplicated selections are counted once');
  assert.deepEqual(scopeOrderItems(items, { selections: [{ id: 's2', phaseId: 'phase1' }] }), [], 'phase identity must match');
});

test('the first phase covers its products with phase-only totals and unit conversion', () => {
  const first = scopeOrderItems(items, { phaseId: 'phase1' });
  assert.deepEqual(first.map(item => [item.name, item.quantityLabel]), [['Heemstwortel', '210 g'], ['Zink', '1,4 kg']]);
  assert.deepEqual(scopeOrderItems(items, { phaseId: 'empty-phase' }), [], 'a phase without products has an empty list');
});

test('the full list is unchanged and unknown amounts are never guessed', () => {
  assert.equal(scopeOrderItems(items, 'all'), items);
  assert.deepEqual(scopeOrderItems(items, null), []);
  const unknown = { ...herb, quantity: null, quantityLabel: null, calculationComplete: false,
    components: [component('s1', 'phase1', null), component('s2', 'phase2', 140)] };
  const [scopedKnown] = scopeOrderItems([unknown], { phaseId: 'phase2' });
  assert.equal(scopedKnown.quantityLabel, '140 g');
  assert.equal(scopedKnown.calculationComplete, true);
  const [scopedUnknown] = scopeOrderItems([unknown], { phaseId: 'phase1' });
  assert.equal(scopedUnknown.quantity, null); assert.equal(scopedUnknown.quantityLabel, null);
  const legacy = { ...herb, components: undefined };
  const [legacyRow] = scopeOrderItems([legacy], { selections: [{ id: 's2' }] });
  assert.equal(legacyRow.quantityLabel, null, 'rows without components never show the full-protocol total for a phase');
  assert.deepEqual(scopeOrderItems([legacy], { phaseId: 'phase2' }), []);
});

test('quantity labels match the server presentation', () => {
  assert.equal(orderQuantityLabel(140, 'g'), '140 g');
  assert.equal(orderQuantityLabel(1500, 'ml'), '1,5 l');
  assert.equal(orderQuantityLabel(2.25, 'eetlepels'), '2,25 eetlepels');
  assert.equal(orderQuantityLabel(1400, 'capsules'), '1400 capsules');
  assert.equal(orderQuantityLabel(0.1 + 0.2, 'g'), '0,3 g');
});
