import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const compile = async path => ts.transpileModule(
  await readFile(new URL(path, import.meta.url), 'utf8'),
  { compilerOptions: { target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS } },
).outputText;
const cacheCode = await compile('../lib/library-thumbnail-cache.ts');
const thumbnailCode = await compile('../components/library/LibraryThumbnail.tsx');
const preloaderCode = await compile('../components/library/LibraryThumbnailPreloader.tsx');
function load(code, modules, globals = {}) {
  const exports = {};
  new Function('require', 'exports', ...Object.keys(globals), code)(
    name => { assert.ok(name in modules, name); return modules[name]; },
    exports, ...Object.values(globals),
  );
  return exports;
}
const url = name => `https://media.example.test/${name}.jpg`;
const settle = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
};
function cache(Image) { return load(cacheCode, { 'expo-image': { Image } }); }

test('preloads unviewed thumbnails once and reuses persisted disk files after a new app session', async () => {
  const disk = new Set(), downloads = [];
  const Image = {
    getCachePathAsync: async uri => disk.has(uri) ? '/cache/image.jpg' : null,
    prefetch: async (uri, policy) => { assert.equal(policy, 'disk'); downloads.push(uri); disk.add(uri); return true; },
  };
  const urls = [url('one'), url('two'), url('one'), '', 'file:///image.jpg'];
  await cache(Image).preloadLibraryThumbnails(urls);
  assert.deepEqual(downloads.sort(), [url('one'), url('two')]);
  await cache(Image).preloadLibraryThumbnails(urls);
  assert.equal(downloads.length, 2);
  disk.delete(url('one'));
  await cache(Image).preloadLibraryThumbnails(urls);
  assert.equal(downloads.length, 3, 'an evicted image is downloaded again');
  await cache(Image).preloadLibraryThumbnails([url('replacement')]);
  assert.equal(downloads.at(-1), url('replacement'));
});

test('individual download failures do not stop the batch and retry successfully after recovery', async () => {
  let online = false;
  const attempts = [], ready = [], disk = new Set();
  const service = cache({
    getCachePathAsync: async uri => disk.has(uri) ? '/cache/image.jpg' : null,
    prefetch: async uri => {
      attempts.push(uri);
      if (!online && uri === url('one')) throw new Error('Backend unavailable');
      if (!online && uri === url('two')) return false;
      disk.add(uri); return true;
    },
  });
  const unsubscribe = service.onThumbnailCached(uri => ready.push(uri));
  const urls = [url('one'), url('two'), url('three')];
  await service.preloadLibraryThumbnails(urls);
  assert.deepEqual(ready, [url('three')]);
  online = true;
  await service.preloadLibraryThumbnails(urls);
  assert.equal(attempts.length, 5);
  assert.ok(ready.includes(url('one')));
  assert.ok(ready.includes(url('two')));
  unsubscribe();
  const count = ready.length;
  await service.preloadLibraryThumbnails(urls);
  assert.equal(ready.length, count);
});

test('limits downloads, deduplicates overlapping runs, and cancels queued work', async () => {
  const release = deferred(), calls = [];
  let cancelled = false;
  const service = cache({
    getCachePathAsync: async () => null,
    prefetch: async uri => { calls.push(uri); await release.promise; return true; },
  });
  const first = service.preloadLibraryThumbnails(
    ['one', 'two', 'three', 'four', 'five'].map(url), () => cancelled,
  );
  const overlapping = service.preloadLibraryThumbnails([url('one')]);
  await settle();
  assert.deepEqual(calls, ['one', 'two', 'three'].map(url));
  cancelled = true;
  release.resolve();
  await Promise.all([first, overlapping]);
  assert.equal(calls.length, 3);
});

// Render the production thumbnail and flush its real effects; only the native
// image/cache boundary is substituted because Node cannot mount Android views.
function hooks() {
  const states = [], effects = [];
  let cursor = 0, pending = [];
  const react = {
    useState(initial) {
      const index = cursor++;
      if (!(index in states)) states[index] = initial;
      return [states[index], value => { states[index] = value; }];
    },
    useEffect(effect, deps) {
      const index = cursor++;
      if (!effects[index] || deps.some((value, i) => value !== effects[index].deps[i])) {
        pending.push(() => {
          effects[index]?.cleanup?.();
          effects[index] = { deps, cleanup: effect() };
        });
      }
    },
  };
  return {
    react,
    render(fn) {
      cursor = 0;
      const result = fn();
      const flush = pending; pending = []; flush.forEach(effect => effect());
      return result;
    },
    cleanup() { effects.forEach(effect => effect?.cleanup?.()); },
  };
}
const jsx = (type, props, key) => ({ type, props, key });
const flat = node => Array.isArray(node) ? node.flatMap(flat)
  : node && typeof node === 'object' ? [node, ...flat(node.props?.children)] : [];

test('thumbnail uses the same disk cache and recovers its fallback after preloading succeeds', async () => {
  const service = cache({ getCachePathAsync: async () => null, prefetch: async () => true });
  const h = hooks();
  const { LibraryThumbnail } = load(thumbnailCode, {
    react: h.react, 'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { View: 'View' }, 'expo-image': { Image: 'CachedImage' },
    'lucide-react-native': { BookOpen: 'BookOpen', Film: 'Film', Music: 'Music' },
    '@/lib/library-thumbnail-cache': service,
  });
  let uri = url('one');
  const render = () => flat(h.render(() => LibraryThumbnail({ uri, format: 'video' })));
  const image = () => render().find(node => node.type === 'CachedImage');
  assert.equal(image().props.cachePolicy, 'disk');
  assert.equal(image().props.source.uri, uri);
  image().props.onError();
  assert.equal(image(), undefined);
  assert.ok(render().some(node => node.type === 'Film'));
  await service.preloadLibraryThumbnails([url('other')]);
  assert.equal(image(), undefined);
  await service.preloadLibraryThumbnails([uri]);
  assert.equal(image().props.source.uri, uri);
  image().props.onError(); render();
  uri = url('replacement'); render();
  assert.equal(image().props.source.uri, uri);
  h.cleanup();
});

test('preloader starts from synced rows, retries on foreground/timer, and stops on cleanup', async () => {
  let effect, timer, listener, removed = false, intervalCleared = false;
  const calls = [];
  const AppState = {
    currentState: 'active',
    addEventListener: (_event, callback) => {
      listener = callback; return { remove() { removed = true; } };
    },
  };
  const { LibraryThumbnailPreloader } = load(preloaderCode, {
    react: { useEffect: callback => { effect = callback; } },
    'react-native': { AppState },
    '@/db/hooks': { useLibraryItems: () => [
      { heroImageUrl: url('one') }, { heroImageUrl: url('one') },
      { heroImageUrl: url('two') }, { heroImageUrl: null },
    ] },
    '@/db/provider': { useDb: () => ({ isLoggedIn: true, currentUserId: 'user', isConnected: false }) },
    '@/lib/library-thumbnail-cache': {
      preloadLibraryThumbnails: async (urls, cancelled) => { calls.push({ urls, cancelled }); },
    },
  }, {
    setInterval(callback, delay) { assert.equal(delay, 60_000); timer = callback; return 1; },
    clearInterval(id) { assert.equal(id, 1); intervalCleared = true; },
  });
  LibraryThumbnailPreloader();
  const cleanup = effect();
  await settle();
  assert.deepEqual(calls[0].urls, [url('one'), url('two')]);
  AppState.currentState = 'background'; timer(); listener('background');
  await settle(); assert.equal(calls.length, 1);
  assert.equal(calls[0].cancelled(), true);
  AppState.currentState = 'active'; listener('active');
  await settle(); assert.equal(calls.length, 2);
  timer(); await settle(); assert.equal(calls.length, 3);
  cleanup();
  assert.equal(removed, true); assert.equal(intervalCleared, true);
  assert.equal(calls[2].cancelled(), true);
  timer(); await settle(); assert.equal(calls.length, 3);
});

test('native app mounts preloading inside the database provider before screen navigation', async () => {
  const source = await readFile(new URL('../app/_layout.tsx', import.meta.url), 'utf8');
  assert.match(source, /<DbProvider>\s*<LibraryThumbnailPreloader \/>/);
});
