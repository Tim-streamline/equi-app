import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const tick = () => new Promise(resolve => setImmediate(resolve));
function load(path, modules) {
  const exports = {};
  const code = ts.transpileModule(readFileSync(new URL(path, import.meta.url), 'utf8'), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX },
  }).outputText;
  new Function('require', 'exports', code)(name => { assert.ok(name in modules, name); return modules[name]; }, exports);
  return exports;
}
function fixture(t, { storage, token, cache = { get: async () => undefined, put: async () => {} } }) {
  const hooks = [], effects = [], timers = [], tokenSignals = [];
  let index = 0;
  t.mock.method(globalThis, 'setTimeout', callback => { timers.push(callback); return timers.length; });
  t.mock.method(globalThis, 'clearTimeout', () => {});
  const queue = load('../lib/intake/attachments/preview.web.ts', { './preview-cache.web': { previewCache: cache } });
  const jsx = (type, props) => { if (props?.ref) props.ref.current = {}; return { type, props }; };
  const component = load('../components/intake/AttachmentPhoto.web.tsx', {
    react: {
      useRef: initial => { const i = index++; return hooks[i] ??= { current: initial }; },
      useState: initial => {
        const i = index++; if (!(i in hooks)) hooks[i] = initial;
        return [hooks[i], next => { hooks[i] = typeof next === 'function' ? next(hooks[i]) : next; }];
      },
      useEffect: (effect, deps) => {
        const i = index++, old = hooks[i];
        if (!old || deps.some((value, n) => value !== old.deps[n])) effects.push(() => {
          old?.cleanup?.(); hooks[i] = { deps, cleanup: effect() };
        });
      },
    },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    '@/db/auth': { getApiBaseUrl: () => 'https://api.example.test' },
    '@/db/connector': { getOrMintToken: signal => { tokenSignals.push(signal); return token(signal); } },
    '@/db/provider': { useDb: () => ({ currentUserId: 'owner' }) },
    '@/lib/intake/attachments/storage': { attachmentStorage: { get: storage } },
    '@/lib/intake/attachments/preview.web': queue,
  });
  const draw = () => {
    index = 0;
    const tree = component.Photo({ reference: 'attachment:photo-id:photo.jpg' });
    for (const effect of effects.splice(0)) effect();
    return tree;
  };
  const unmount = () => { for (const hook of hooks) hook?.cleanup?.(); };
  t.after(unmount);
  draw(); draw();
  return { draw, unmount, timeout: () => timers.at(-1)(), tokenSignals, queue };
}

test('an unavailable local database still loads the authenticated server thumbnail', async t => {
  const requests = [], revoked = [];
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    requests.push({ url, options });
    return new Response(new Blob(['thumbnail'], { type: 'image/jpeg' }), { headers: { 'X-Intake-Preview': '1' } });
  });
  t.mock.method(URL, 'createObjectURL', () => 'blob:thumbnail');
  t.mock.method(URL, 'revokeObjectURL', url => revoked.push(url));
  const unavailable = async () => { throw new Error('IndexedDB unavailable'); };
  const app = fixture(t, { storage: unavailable, token: async () => 'test-token', cache: { get: unavailable, put: unavailable } });
  await tick(); await tick();
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, 'https://api.example.test/api/intake-media/photo-id?preview=1');
  assert.equal(requests[0].options.headers.Authorization, 'Bearer test-token');
  assert.equal(app.draw().props.children.type, 'img');
  app.unmount();
  assert.ok(revoked.includes('blob:thumbnail'));
});

for (const reason of ['timeout', 'unmount']) test(`${reason} releases the queue while a shared token request is still pending`, async t => {
  const requests = [];
  t.mock.method(globalThis, 'fetch', async url => { requests.push(url); throw new Error('Unexpected fetch'); });
  let finishToken;
  // Shared authentication requests may ignore a new caller's abort signal.
  const app = fixture(t, { storage: async () => null, token: () => new Promise(resolve => { finishToken = resolve; }) });
  await tick();
  assert.equal(app.tokenSignals.length, 1);
  let nextStarted = false;
  const next = app.queue.queuePhotoPreview(async () => {
    nextStarted = true;
    return { preview: new Blob(['next thumbnail']) };
  }, new AbortController().signal);
  await tick();
  assert.equal(nextStarted, false);
  app[reason]();
  await tick(); await tick();
  assert.equal(nextStarted, true, 'another preview must start before authentication settles');
  assert.equal(app.tokenSignals[0]?.aborted, true);
  assert.equal(await (await next).text(), 'next thumbnail');
  finishToken('late-token');
  await tick();
  assert.deepEqual(requests, [], 'late credentials must not load the aborted photo');
  assert.notEqual(app.draw().props.children.type, 'img');
});
