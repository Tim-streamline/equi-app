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
async function buckets(user, item, access = grants, client = {}) {
  const index = access.flatMap(record => rules.evaluateParameterRow(sourceTable('library_item_access'), record));
  const { querier, errors } = rules.getBucketParameterQuerier({
    globalParameters: new RequestParameters(new BaseJwtPayload({ sub: user }), client), hasDefaultStreams: false,
    streams: { library_content: [{ parameters: { item_id: item }, opaque_id: 0, priorityOverride: null }] },
  });
  assert.deepEqual(errors, []);
  const dynamic = await querier.queryDynamicBucketDescriptions({
    getParameterSets: async lookups => lookups.map(lookup => ({
      lookup, rows: index.filter(entry => entry.lookup.serializedRepresentation === lookup.serializedRepresentation).flatMap(entry => entry.bucketParameters),
    })),
  });
  return [...new Set([...querier.staticBuckets, ...dynamic].map(bucket => bucket.bucket))];
}
assert.deepEqual(await buckets('alice', 'lesson-a'), [itemBuckets[0].bucket]);
assert.deepEqual(await buckets('bob', 'lesson-a'), [itemBuckets[0].bucket]);
assert.deepEqual(await buckets('mallory', 'lesson-a'), []);
assert.deepEqual(await buckets('mallory', 'lesson-a', grants, { user_id: 'alice', allowedIds: ['lesson-a'] }), []);
assert.deepEqual(await buckets('bob', 'lesson-b'), []);
assert.deepEqual(await buckets('alice', 'lesson-a', grants.filter(g => g.id !== 'grant-a')), []);
assert.deepEqual(await buckets('bob', 'lesson-a', grants.filter(g => g.id !== 'grant-a')), [itemBuckets[0].bucket]);
console.log('PASS: one shared bucket per item; authorized users share it; forged requests and revoked grants receive no bucket.');
