// Run with the installed PowerSync compiler, without changing the running service:
// docker exec -i backend-powersync-1 node --input-type=module < backend/tests/powersync/protocol-clients.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
import { SqlSyncRules, DEFAULT_HYDRATION_STATE, RequestParameters, BaseJwtPayload } from '/app/packages/sync-rules/dist/index.js';
const { config, errors } = SqlSyncRules.fromYaml(fs.readFileSync('/config/sync_rules.yaml', 'utf8'), { defaultSchema: 'public' });
assert.deepEqual(errors, []);
const rules = config.hydrate({ hydrationState: DEFAULT_HYDRATION_STATE });
const table = name => ({ name, schema: 'public', connectionTag: 'default' });
const records = {
  horses: [{ id: 'horse', owner_id: 'alice', status: 'active' }],
  protocols: [{ id: 'plan', horse_id: 'horse', published_at: '2026-11-01' }],
  protocol_phases: [{ id: 'phase', protocol_id: 'plan' }],
  protocol_phase_supplements: [{ id: 'herb', protocol_phase_id: 'phase' }],
};
const index = Object.entries(records).flatMap(([name, rows]) => rows.flatMap(row => rules.evaluateParameterRow(table(name), row)));
async function delivered(claims, done = false, client = {}) {
  const projected = [1, 2].flatMap(administration => rules.evaluateRow({ sourceTable: table('protocol_supplement_intakes'), record: {
    id: `dose-${administration}`, protocol_phase_supplement_id: 'herb', horse_id: 'horse', date: '2026-11-04',
    dosage: '10 g', administration: BigInt(administration), done: administration === 1 ? 1n : 0n, day_done: done ? 1n : 0n, taken_at: null,
  } }));
  const { querier, errors } = rules.getBucketParameterQuerier({
    globalParameters: new RequestParameters(new BaseJwtPayload({ sub: 'alice', subscribed: 1, ...claims }), client),
    hasDefaultStreams: true, streams: {},
  });
  assert.deepEqual(errors, []);
  const dynamic = await querier.queryDynamicBucketDescriptions({ getParameterSets: async lookups => lookups.map(lookup => ({
    lookup, rows: index.filter(entry => entry.lookup.serializedRepresentation === lookup.serializedRepresentation).flatMap(entry => entry.bucketParameters),
  })) });
  const buckets = new Set([...querier.staticBuckets, ...dynamic].map(bucket => bucket.bucket));
  return projected.filter(row => buckets.has(row.bucket));
}
const legacy = await delivered({});
assert.equal(legacy.length, 1, 'legacy SELECT/LIMIT 1 must see exactly one product-day row');
assert.equal(legacy[0].id, 'dose-1');
assert.equal(Number(legacy[0].data.done), 0, 'morning alone does not complete the legacy whole day');
assert.equal(Number((await delivered({}, true))[0].data.done), 1);
// Exercise the released master's SQLite lookup/toggle against the actual projection.
// Its schema has no administration column (expo-app/db/hooks.ts at c60f0cf).
const sqlite = new DatabaseSync(':memory:');
sqlite.exec('CREATE TABLE protocol_supplement_intakes (id TEXT PRIMARY KEY, protocol_phase_supplement_id TEXT, date TEXT, done INTEGER, taken_at TEXT)');
for (const row of legacy) {
  sqlite.prepare('INSERT INTO protocol_supplement_intakes VALUES (?, ?, ?, ?, NULL)')
    .run(row.id, row.data.protocol_phase_supplement_id, row.data.date, Number(row.data.done));
}
const existing = sqlite.prepare(`SELECT id, done FROM protocol_supplement_intakes
           WHERE protocol_phase_supplement_id = ? AND date = ? LIMIT 1`).get('herb', '2026-11-04');
assert.equal(existing.id, 'dose-1');
assert.equal(existing.done, 0);
sqlite.prepare('UPDATE protocol_supplement_intakes SET done = ?, taken_at = ? WHERE id = ?')
  .run(existing.done ? 0 : 1, '2026-11-04T12:00:00Z', existing.id);
assert.equal(sqlite.prepare('SELECT done FROM protocol_supplement_intakes WHERE id = ?').get('dose-1').done, 1);
sqlite.close();
const modern = await delivered({ protocol_doses: 1 });
assert.equal(modern.length, 2);
assert.deepEqual(modern.map(row => Number(row.data.administration)).sort(), [1, 2]);
assert.deepEqual(modern.map(row => Number(row.data.done)), [1, 0]);
assert.equal((await delivered({}, false, { protocol_doses: 1 })).length, 1, 'request parameters cannot replace signed capabilities');
assert.deepEqual(await delivered({ sub: 'mallory' }), []);
assert.deepEqual(await delivered({ subscribed: 0, protocol_doses: 1 }), []);
console.log('PASS: legacy gets one whole-day row; modern gets independent doses; signed capabilities, ownership and subscription enforced.');
