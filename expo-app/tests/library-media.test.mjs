import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
const code = ts.transpileModule(readFileSync(new URL('../components/library/LibraryMedia.tsx', import.meta.url), 'utf8'), { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX } }).outputText;
const flat = node => Array.isArray(node) ? node.flatMap(flat) : node && typeof node === 'object' ? [node, ...flat(node.props?.children)] : [node];
function harness(platform = 'android') {
  const cleanups = [], blurs = [], players = [], sources = [];
  const jsx = (type, props) => ({ type, props });
  let current;
  const modules = {
    react: { useMemo: fn => fn(), useCallback: fn => fn, useEffect: fn => cleanups.push(fn()) },
    'react/jsx-runtime': { jsx, jsxs: jsx },
    'react-native': { View: 'View', Text: 'Text', Pressable: 'Pressable', Platform: { OS: platform } },
    'expo-router': { useFocusEffect: fn => { const cleanup = fn(); blurs.push(cleanup); cleanups.push(cleanup); } },
    expo: { useEvent: (_p, name, initial) => name === 'timeUpdate' ? { currentTime: current.currentTime } : name === 'sourceLoad' ? { duration: current.duration } : initial },
    'expo-video': { VideoView: 'VideoView', useVideoPlayer: (source, setup) => {
      const listeners = new Set();
      let released = false, notification = false;
      const assertLive = () => {
        if (released) throw Object.assign(new Error('Cannot use shared object that was already released'), { code: 'ERR_USING_RELEASED_SHARED_OBJECT' });
      };
      current = { playing: false, status: 'readyToPlay', currentTime: 40, duration: 1200,
        addListener: (_name, fn) => { listeners.add(fn); return { remove: () => listeners.delete(fn) }; },
        play() { this.playing = true; listeners.forEach(fn => fn({ isPlaying: true })); },
        pause() { assertLive(); this.playing = false; listeners.forEach(fn => fn({ isPlaying: false })); },
        release() { released = true; this.playing = false; notification = false; listeners.clear(); },
        get showNowPlayingNotification() { return notification; },
        set showNowPlayingNotification(value) { assertLive(); notification = value; },
      };
      // Expo's useVideoPlayer registers release before our focus cleanup.
      const player = current;
      cleanups.push(() => player.release());
      setup(current); players.push(current); sources.push(source); return current;
    } },
    'lucide-react-native': Object.fromEntries(['Pause', 'Play', 'RotateCcw', 'RotateCw'].map(n => [n, n])),
  };
  const exports = {};
  new Function('require', 'exports', code)(name => modules[name], exports);
  const render = (audio = false) => flat(exports.LibraryMedia({ url: audio ? 'https://example.test/audio.mp3' : 'https://example.test/video.mp4', audio, metadata: { title: 'Hoefgezondheid', artist: 'Shelley', artwork: 'https://example.test/cover.jpg' } }));
  return { render, players, sources, blurs, close: () => cleanups.forEach(fn => fn?.()) };
}
for (const platform of ['android', 'ios', 'web']) test(`${platform}: media metadata and controls reach the real player configuration`, () => {
  const h = harness(platform);
  const video = h.render();
  const first = h.players[0];
  assert.equal(first.staysActiveInBackground, true);
  assert.equal(first.showNowPlayingNotification, true);
  assert.equal(first.audioMixingMode, 'auto');
  assert.deepEqual(h.sources[0].metadata, { title: 'Hoefgezondheid', artist: 'Shelley', artwork: 'https://example.test/cover.jpg' });
  assert.equal(video.find(n => n?.type === 'VideoView').props.style.width, '100%');
  assert.equal(video.find(n => n?.type === 'VideoView').props.style.aspectRatio, 16 / 9);
  first.play();
  const nodes = h.render(true), audio = h.players[1];
  const button = label => nodes.find(n => n?.props?.accessibilityLabel === label);
  button('Audio afspelen').props.onPress();
  assert.equal(audio.playing, true); assert.equal(first.playing, false, 'only one media session plays');
  button('15 seconden vooruit').props.onPress(); assert.equal(audio.currentTime, 55);
  audio.currentTime = 2; button('15 seconden terug').props.onPress(); assert.equal(audio.currentTime, 0);
  audio.currentTime = 1198; button('15 seconden vooruit').props.onPress(); assert.equal(audio.currentTime, 1200);
  button('Audio afspelen').props.onPress(); assert.equal(audio.playing, false);
  button('Audio afspelen').props.onPress(); assert.equal(audio.currentTime, 0); assert.equal(audio.playing, true);
  if (platform === 'web') assert.ok(nodes.some(n => n?.type === 'VideoView'), 'web audio requires a mounted HTML media element');
  h.blurs[1](); assert.equal(audio.playing, false); assert.equal(audio.showNowPlayingNotification, false);
  h.close();
});

test('popping a reader survives Expo releasing its player before focus cleanup', () => {
  const h = harness();
  h.render();
  h.players[0].play();
  assert.doesNotThrow(() => h.close());
  assert.equal(h.players[0].playing, false);
  assert.equal(h.players[0].showNowPlayingNotification, false);
});

test('replaced players tolerate late blur, while unexpected player errors remain visible', () => {
  const h = harness();
  h.render();
  h.players[0].release();
  assert.doesNotThrow(() => h.blurs[0]());
  h.render();
  const failure = Object.assign(new Error('Unexpected native failure'), { code: 'ERR_OTHER' });
  const pause = h.players[1].pause;
  h.players[1].pause = () => { throw failure; };
  assert.throws(() => h.blurs[1](), error => error === failure);
  h.players[1].pause = pause;
  h.close();
});
