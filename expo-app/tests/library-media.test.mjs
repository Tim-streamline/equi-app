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
    'expo-router': { useFocusEffect: fn => blurs.push(fn()) },
    expo: { useEvent: (_p, name, initial) => name === 'timeUpdate' ? { currentTime: current.currentTime } : name === 'sourceLoad' ? { duration: current.duration } : initial },
    'expo-video': { VideoView: 'VideoView', useVideoPlayer: (source, setup) => {
      const listeners = new Set();
      current = { playing: false, status: 'readyToPlay', currentTime: 40, duration: 1200,
        addListener: (_name, fn) => { listeners.add(fn); return { remove: () => listeners.delete(fn) }; },
        play() { this.playing = true; listeners.forEach(fn => fn({ isPlaying: true })); },
        pause() { this.playing = false; listeners.forEach(fn => fn({ isPlaying: false })); },
      };
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
