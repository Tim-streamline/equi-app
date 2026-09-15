import assert from 'node:assert/strict';
import test from 'node:test';
import { AccountSessionQueue } from '../lib/account-session.ts';
function deferred() { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; }
test('new login waits for all old logout cleanup', async () => {
  const queue = new AccountSessionQueue(); const release = deferred(); const events = [];
  const logout = queue.transition(async () => { events.push('logout'); await release.promise; events.push('clear old credentials'); });
  await Promise.resolve();
  const login = queue.transition(async () => { events.push('new login'); });
  await Promise.resolve(); assert.deepEqual(events, ['logout']);
  release.resolve(); await Promise.all([logout, login]);
  assert.deepEqual(events, ['logout', 'clear old credentials', 'new login']);
});
test('in-flight registration completes before unregister and queued old registration cannot reinstall token', async () => {
  const queue = new AccountSessionQueue(); const revision = queue.revision; const release = deferred(); const events = [];
  const register = queue.forSession(revision, async () => { await release.promise; events.push('register'); });
  await Promise.resolve();
  const logout = queue.transition(async () => { events.push('unregister'); });
  const stale = queue.forSession(revision, async () => { events.push('stale register'); });
  release.resolve(); await Promise.all([register, logout, stale]);
  assert.deepEqual(events, ['register', 'unregister']);
});
test('old registration stays invalid across a new login and failed operations release the queue', async () => {
  const queue = new AccountSessionQueue(); const revision = queue.revision;
  await assert.rejects(queue.transition(async () => { throw new Error('network'); }), /network/);
  await queue.transition(async () => {});
  let submitted = false; await queue.forSession(revision, async () => { submitted = true; }); assert.equal(submitted, false);
  await queue.forSession(queue.revision, async () => { submitted = true; }); assert.equal(submitted, true);
});
