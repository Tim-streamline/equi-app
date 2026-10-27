import test from 'node:test';
import assert from 'node:assert/strict';
import { libraryAccessLabel, libraryCanRead } from '../lib/library.ts';
import { activeLibraryGrants } from '../lib/library-sync.ts';
import { filterLibraryItems } from '../lib/library-filter.ts';
const access = { hasPlus: true, hasEquiBoost: false, unlockedIds: [] };
const only = { id:'only', equiboostAccess:'only', creditCost:0 };
const combo = { id:'combo', equiboostAccess:'included', creditCost:1 };

test('a normal subscription does not unlock either EquiBoost mode or label Plus-only as free', () => {
  assert.equal(libraryCanRead(only, access), false);
  assert.deepEqual(libraryAccessLabel(only, access), {label:'Plus only · EquiBoost', locked:true});
  assert.equal(libraryCanRead(combo, access), false);
  assert.equal(libraryAccessLabel(combo, access).label, '1 credit · gratis tijdens EquiBoost');
  assert.equal(libraryAccessLabel({...combo, creditCost: 3}, access).label, '3 credits · gratis tijdens EquiBoost');
  assert.equal(libraryCanRead({id:'regular', isPlus:true}, access), true);
});
test('an active EquiBoost grant unlocks both modes, but stale HTTP state cannot extend the deadline', () => {
  const active = {...access, hasEquiBoost:true, equiBoostExpiresAt:'2999-01-01T00:00:00Z'};
  for(const item of [only, combo]) {
    assert.equal(libraryCanRead(item, active), true);
    assert.deepEqual(libraryAccessLabel(item, active), {label:'Inbegrepen bij EquiBoost', locked:false});
    assert.equal(libraryCanRead(item, {...active, equiBoostExpiresAt:'2000-01-01T00:00:00Z'}), false);
    assert.equal(libraryCanRead(item, {...active, allowedIds:[]}), false);
  }
});
test('credit purchases remain accessible independently of the EquiBoost period', () => {
  const purchased = {...access, unlockedIds:['only','combo']};
  for(const item of [only, combo]) {
    assert.equal(libraryCanRead(item, purchased), true);
    assert.deepEqual(libraryAccessLabel(item, purchased), {label:'Ontgrendeld', locked:false});
  }
});
test('EquiBoost grants expire offline exactly at their deadline and do not become purchases', () => {
  const rows = [{user_id:'u', item_id:'only', reason:'equiboost', expires_at:'2027-01-27T12:00:00Z'}];
  assert.equal(activeLibraryGrants(rows, Date.parse('2027-01-27T11:59:59Z')).length, 1);
  assert.equal(activeLibraryGrants(rows, Date.parse('2027-01-27T12:00:00Z')).length, 0);
});
test('only accessible filtering does not treat a zero-price Plus-only item as generally free', () => {
  const items = [only, combo, {id:'free', creditCost:0}];
  assert.deepEqual(filterLibraryItems(items, [], [], '', {accessibleOnly:true, access}).map(i=>i.id), ['free']);
});
