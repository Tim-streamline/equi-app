// Run with the installed service compiler:
// docker exec -i backend-powersync-1 node --input-type=module < tests/powersync/library-content.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { SqlSyncRules, DEFAULT_HYDRATION_STATE, RequestParameters, BaseJwtPayload } from '/app/packages/sync-rules/dist/index.js';
const { config, errors } = SqlSyncRules.fromYaml(fs.readFileSync('/config/sync_rules.yaml', 'utf8'), { defaultSchema: 'public' });
assert.deepEqual(errors, []);
const rules = config.hydrate({ hydrationState: DEFAULT_HYDRATION_STATE });
const sourceTable = name => ({ name, schema: 'public', connectionTag: 'default' });
const content = { id: 'lesson-a', body: 'Shared article', chapters: '[]', attachments: '[]' };
const itemBuckets = rules.evaluateRow({ sourceTable: sourceTable('library_contents'), record: content });
assert.equal(itemBuckets.length, 1);
assert.equal(itemBuckets[0].table, 'library_contents');
assert.equal(itemBuckets[0].bucket, 'library_content|0["lesson-a"]');
const grants = [
  { id: 'grant-a', user_id: 'alice', item_id: 'lesson-a', reason: 'unlocked' },
  { id: 'grant-b', user_id: 'bob', item_id: 'lesson-a', reason: 'plus' },
  { id: 'grant-c', user_id: 'alice', item_id: 'lesson-b', reason: 'unlocked' },
];
async function querySubscriptions(user, items, access = grants, client = {}, subscribed = 1) {
  const index = access.flatMap(record => rules.evaluateParameterRow(sourceTable('library_item_access'), record));
  const { querier, errors } = rules.getBucketParameterQuerier({
    globalParameters: new RequestParameters(new BaseJwtPayload({ sub: user, subscribed }), client), hasDefaultStreams: false,
    streams: { library_content: items.map((item, opaque_id) => ({ parameters: { item_id: item }, opaque_id, priorityOverride: null })) },
  });
  assert.deepEqual(errors, []);
  let parameterRows = 0;
  const dynamic = await querier.queryDynamicBucketDescriptions({
    getParameterSets: async lookups => lookups.map(lookup => {
      const rows = index.filter(entry => entry.lookup.serializedRepresentation === lookup.serializedRepresentation).flatMap(entry => entry.bucketParameters);
      parameterRows += rows.length;
      return { lookup, rows };
    }),
  });
  return { buckets: [...new Set([...querier.staticBuckets, ...dynamic].map(bucket => bucket.bucket))], parameterRows };
}
async function buckets(user, item, access = grants, client = {}, subscribed = 1) {
  return (await querySubscriptions(user, [item], access, client, subscribed)).buckets;
}
assert.deepEqual(await buckets('alice', 'lesson-a'), [itemBuckets[0].bucket]);
assert.deepEqual(await buckets('bob', 'lesson-a'), [itemBuckets[0].bucket]);
assert.deepEqual(await buckets('mallory', 'lesson-a'), []);
assert.deepEqual(await buckets('mallory', 'lesson-a', grants, { user_id: 'alice', allowedIds: ['lesson-a'] }), []);
assert.deepEqual(await buckets('bob', 'lesson-b'), []);
assert.deepEqual(await buckets('alice', 'lesson-a', grants.filter(g => g.id !== 'grant-a')), []);
assert.deepEqual(await buckets('bob', 'lesson-a', grants.filter(g => g.id !== 'grant-a')), [itemBuckets[0].bucket]);
console.log('PASS: one shared bucket per item; authorized users share it; forged requests and revoked grants receive no bucket.');

assert.deepEqual(await buckets('alice', 'lesson-a', grants, {subscribed:1}, 0), []);
const groups = rules.evaluateRow({ sourceTable: sourceTable('library_filter_groups'), record: {id:'group-a',slug:'topic',label:'Onderwerp',active:true,order:1} });
assert.ok(groups.some(row => row.table === 'library_filter_groups'));
console.log('PASS: filter groups replicate; subscription claims cannot be forged through client parameters.');

// Each subscription must look up its own grant, rather than enumerate every
// grant again. Otherwise 100 granted items generate 10,000 parameter rows and
// disconnect the whole client at the service's 1,000-result limit.
const manyGrants = Array.from({ length: 100 }, (_, n) => ({
  id: `grant-${n}`, user_id: 'alice', item_id: `lesson-${n}`, reason: 'unlocked',
}));
const many = await querySubscriptions('alice', [...manyGrants.map(g => g.item_id), 'restricted'], [
  ...manyGrants,
  { id: 'restricted-grant', user_id: 'bob', item_id: 'restricted', reason: 'unlocked' },
]);
assert.deepEqual(many.buckets.sort(), manyGrants.map(g => `library_content|0["${g.item_id}"]`).sort());
assert.equal(many.parameterRows, manyGrants.length, 'Grant lookups must scale with subscribed items, not items multiplied by all grants.');
console.log('PASS: 100 subscriptions resolve 100 grant rows and exclude another user\'s content.');
