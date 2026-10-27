import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const exports = {};
const js = ts.transpileModule(readFileSync(new URL('../lib/shelby.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
new Function('require', 'exports', js)(name => { assert.equal(name, '@react-native-async-storage/async-storage'); return { default: {} }; }, exports);
const shelby = exports;

test('the introduction is shown once per device, even if the app closes right away', async () => {
  const saved = new Map();
  const storage = { getItem: async key => saved.get(key) ?? null, setItem: async (key, value) => { saved.set(key, value); } };
  assert.equal(await shelby.claimShelbyIntro(storage), true);
  assert.equal(await shelby.claimShelbyIntro(storage), false);
});

test('without working storage the introduction stays hidden instead of repeating', async () => {
  const broken = { getItem: async () => { throw new Error('unavailable'); }, setItem: async () => {} };
  assert.equal(await shelby.claimShelbyIntro(broken), false);
});

test('Shelby introduces herself with the agreed texts and types for five seconds', () => {
  assert.equal(shelby.SHELBY_INTRO, 'Hoi! Ik ben Shelby. Heb je een vraag over je paard? Ik denk graag met je mee!');
  assert.equal(shelby.SHELBY_SUBTITLE, 'Jouw persoonlijke paardenassistent');
  assert.match(shelby.SHELBY_WELCOME, /^Hoi! Ik ben Shelby! 👋\n/);
  assert.match(shelby.SHELBY_WELCOME, /Waar kan ik je vandaag mee helpen\?$/);
  assert.equal(shelby.SHELBY_TYPING_MS, 5000);
});

test('a chat from an earlier day is not shown as today\'s conversation', () => {
  const now = new Date('2026-10-27T15:00:00');
  assert.equal(shelby.isSameLocalDay(new Date('2026-10-27T08:00:00').toISOString(), now), true);
  assert.equal(shelby.isSameLocalDay(new Date('2026-10-26T20:00:00').toISOString(), now), false);
  assert.equal(shelby.isSameLocalDay('', now), false);
});

test('synced and just-received messages are shown once, with the Plus button only where the server allows it', () => {
  const synced = [{ id: 'q1', role: 'user', body: 'Vraag' }, { id: 'a1', role: 'assistant', body: 'Antwoord', meta: '{"plus_cta":true,"provider":"openai"}' }];
  const pending = [{ id: 'a1', role: 'assistant', body: 'Antwoord', plusCta: true }, { id: 'q2', role: 'user', body: 'Nog een vraag', plusCta: false }];
  const merged = shelby.mergeShelbyMessages(synced, pending);
  assert.deepEqual(merged.map(m => m.id), ['q1', 'a1', 'q2']);
  assert.equal(merged[1].plusCta, true);
  assert.equal(shelby.plusCtaFromMeta('not json'), false);
  assert.equal(shelby.plusCtaFromMeta(null), false);
});

function api(fetch) {
  const exports = {};
  const js = ts.transpileModule(readFileSync(new URL('../lib/shelby-api.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  const modules = { '@/db/auth': { getApiBaseUrl: () => 'https://api.test' }, '@/db/connector': { getOrMintToken: async () => 'token' }, './shelby': shelby };
  new Function('require', 'exports', 'fetch', js)(name => { assert.ok(name in modules, name); return modules[name]; }, exports, fetch);
  return exports;
}

test('a new daily session drops yesterday\'s pending messages while preserving the new exchange', () => {
  const previous = [{ id: 'old', role: 'assistant', body: 'Gisteren', plusCta: false }];
  const question = { id: 'q', role: 'user', body: 'Vandaag', plusCta: false };
  const received = [question, { id: 'a', role: 'assistant', body: 'Nieuw antwoord', plusCta: false }];
  assert.deepEqual(shelby.receiveShelbyMessages([...previous, question], 'yesterday', 'today', 'q', received), received);
  assert.deepEqual(shelby.receiveShelbyMessages([...previous, question], 'today', 'today', 'q', received), [...previous, ...received]);
  assert.deepEqual(shelby.receiveShelbyMessages([], '', 'first-session', 'q', received), received);
});

test('asking Shelby posts the question and returns both messages', async () => {
  let sent;
  const { askShelby } = api(async (url, init) => {
    sent = { url, body: JSON.parse(init.body), auth: init.headers.Authorization };
    return { ok: true, status: 200, json: async () => ({ session_id: 's1', messages: [{ id: 'c1', role: 'user', body: 'Hoi' }, { id: 'a1', role: 'assistant', body: 'Hoi!', plus_cta: false }] }) };
  });
  const result = await askShelby({ horseId: 'h1', clientId: 'c1', body: 'Hoi' });
  assert.equal(sent.url, 'https://api.test/api/shelby/messages');
  assert.deepEqual(sent.body, { horse_id: 'h1', client_id: 'c1', body: 'Hoi' });
  assert.equal(sent.auth, 'Bearer token');
  assert.equal(result.sessionId, 's1');
  assert.deepEqual(result.messages.map(m => m.role), ['user', 'assistant']);
});

test('limits and outages are explained in Dutch, other errors stay generic', async () => {
  const fail = (status, message) => api(async () => ({ ok: false, status, json: async () => ({ message }) })).askShelby({ horseId: 'h', clientId: 'c', body: 'x' });
  await assert.rejects(fail(429, 'Je hebt vandaag al veel vragen gesteld.'), /veel vragen/);
  await assert.rejects(fail(500, 'SQLSTATE secret'), /Shelby is even niet bereikbaar/);
  await assert.rejects(api(async () => { throw new TypeError('Network request failed'); }).askShelby({ horseId: 'h', clientId: 'c', body: 'x' }), /Shelby is even niet bereikbaar/);
});

test('sending a failed question again reuses its id, a new or edited question gets a new one', () => {
  const failed = { id: 'c1', body: 'Hij heeft mok', horseId: 'h1' };
  const make = () => 'new';
  assert.equal(shelby.questionId(failed, 'Hij heeft mok', 'h1', make), 'c1');
  assert.equal(shelby.questionId(failed, 'Hij heeft mok aan twee benen', 'h1', make), 'new');
  assert.equal(shelby.questionId(failed, 'Hij heeft mok', 'h2', make), 'new');
  assert.equal(shelby.questionId(null, 'Hij heeft mok', 'h1', make), 'new');
});
