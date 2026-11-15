// Run with the installed PowerSync compiler, without changing the running service:
// docker exec -i backend-powersync-1 node --input-type=module < tests/powersync/users-privacy.mjs
//
// User rows hold credentials and admin-only notes. Only an explicit projection of
// the own account may reach a device; share counterparts get display fields only,
// in their own output table so they can never replace the own account row.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { SqlSyncRules, DEFAULT_HYDRATION_STATE, RequestParameters, BaseJwtPayload } from '/app/packages/sync-rules/dist/index.js';
const { config, errors } = SqlSyncRules.fromYaml(fs.readFileSync('/config/sync_rules.yaml', 'utf8'), { defaultSchema: 'public' });
assert.deepEqual(errors, []);
const rules = config.hydrate({ hydrationState: DEFAULT_HYDRATION_STATE });
const table = name => ({ name, schema: 'public', connectionTag: 'default' });

const SELF = ['avatar_initial', 'avatar_url', 'created_at', 'email', 'id', 'locale', 'name', 'notifications_on', 'onboarded_at', 'units_system', 'updated_at'];
const SHARED = ['avatar_initial', 'avatar_url', 'created_at', 'id', 'name', 'updated_at'];
const user = id => ({
  id, email: `${id}@example.test`, name: id, avatar_initial: id[0].toUpperCase(), avatar_url: null, locale: 'nl',
  units_system: 'metric', notifications_on: 1n, onboarded_at: null, created_at: '2026-01-01', updated_at: '2026-01-01',
  password: '$2y$12$secret-hash', remember_token: 'remember', admin_note: 'internal note', disabled_at: null,
  email_verified_at: '2026-01-01',
});
const users = ['alice', 'bob', 'carol', 'mallory'].map(user);

async function delivered(sub, { subscribed = 1, horses = [], shares = [] } = {}) {
  const index = [
    ...horses.flatMap(row => rules.evaluateParameterRow(table('horses'), row)),
    ...shares.flatMap(row => rules.evaluateParameterRow(table('horse_shares'), row)),
  ];
  const { querier, errors } = rules.getBucketParameterQuerier({
    globalParameters: new RequestParameters(new BaseJwtPayload({ sub, subscribed }), {}),
    hasDefaultStreams: true, streams: {},
  });
  assert.deepEqual(errors, []);
  const dynamic = await querier.queryDynamicBucketDescriptions({ getParameterSets: async lookups => lookups.map(lookup => ({
    lookup, rows: index.filter(entry => entry.lookup.serializedRepresentation === lookup.serializedRepresentation).flatMap(entry => entry.bucketParameters),
  })) });
  const buckets = new Set([...querier.staticBuckets, ...dynamic].map(bucket => bucket.bucket));
  return users.flatMap(record => rules.evaluateRow({ sourceTable: table('users'), record }))
    .filter(row => buckets.has(row.bucket))
    .map(row => ({ table: row.table, id: row.id, columns: Object.keys(row.data).sort(), data: row.data }));
}

// Every projection of a users row, whoever receives it, must use an allowlist.
for (const row of users.flatMap(record => rules.evaluateRow({ sourceTable: table('users'), record }))) {
  assert.ok(['users', 'shared_users'].includes(row.table), `unexpected output table ${row.table}`);
  assert.deepEqual(Object.keys(row.data).sort(), row.table === 'users' ? SELF : SHARED);
}

// The own account syncs without a subscription, with email but never credentials.
for (const subscribed of [0, 1]) {
  const own = await delivered('alice', { subscribed });
  assert.deepEqual(own.map(row => [row.table, row.id]), [['users', 'alice']]);
  assert.deepEqual(own[0].columns, SELF);
  assert.equal(own[0].data.email, 'alice@example.test');
}
console.log('PASS: the own account row carries only the self allowlist, with or without a subscription.');

const horses = [{ id: 'horse-a', owner_id: 'alice' }, { id: 'horse-m', owner_id: 'mallory' }];
const shares = [{ id: 'share-ab', horse_id: 'horse-a', grantee_user_id: 'bob' }];
const owner = await delivered('alice', { horses, shares });
assert.deepEqual(owner.map(row => [row.table, row.id]).sort(), [['shared_users', 'bob'], ['users', 'alice']]);
const grantee = await delivered('bob', { horses, shares });
assert.deepEqual(grantee.map(row => [row.table, row.id]).sort(), [['shared_users', 'alice'], ['users', 'bob']]);
for (const row of [...owner, ...grantee].filter(row => row.table === 'shared_users')) {
  assert.deepEqual(row.columns, SHARED);
  for (const secret of ['email', 'password', 'remember_token', 'admin_note', 'disabled_at']) assert.ok(!(secret in row.data), secret);
}
console.log('PASS: both share directions deliver the counterpart as shared_users with display fields only.');

const outsider = await delivered('carol', { horses, shares });
assert.deepEqual(outsider.map(row => [row.table, row.id]), [['users', 'carol']]);
assert.deepEqual((await delivered('bob', { subscribed: 0, horses, shares })).map(row => [row.table, row.id]), [['users', 'bob']]);
console.log('PASS: unrelated and unsubscribed users receive no counterpart rows.');

// A self-share must not let the limited projection replace the own account row.
const self = await delivered('alice', { horses, shares: [{ id: 'share-aa', horse_id: 'horse-a', grantee_user_id: 'alice' }] });
assert.deepEqual(self.map(row => [row.table, row.id]).sort(), [['shared_users', 'alice'], ['users', 'alice']]);
assert.deepEqual(self.find(row => row.table === 'users').columns, SELF);
console.log('PASS: a self-share yields a separate shared_users row; the own users row keeps its projection.');
