import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const sources = await Promise.all([
  '../components/library/MarkdownBody.tsx',
  '../app/(tabs)/library/video/[id].tsx',
  '../app/(tabs)/library/article/[id].tsx',
  '../components/library/LibraryContent.tsx',
].map(async (path) => ts.transpileModule(await readFile(new URL(path, import.meta.url), 'utf8'), {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS },
}).outputText));

// Render the real screen and markdown components with native views/player stubbed.
// This verifies that synced CMS content reaches the player, not native playback.
function renderScreen(format, body, chapters = [], canRead = true) {
  const videoSources = [];
  const jsx = (type, props) => typeof type === 'function' ? type(props) : { type, props };
  const modules = {
    'react/jsx-runtime': { jsx, jsxs: jsx, Fragment: 'Fragment' },
    react: { useMemo: (fn) => fn(), useRef: (value) => ({ current: value }), useState: (value) => [value, () => {}] },
    'react-native': Object.fromEntries(['View', 'Text', 'ScrollView', 'Image', 'Pressable'].map((name) => [name, name])),
    'expo-video': {
      VideoView: 'VideoView',
      useVideoPlayer: (url) => { videoSources.push(url); return { url }; },
    },
    'expo-web-browser': { openBrowserAsync: () => {} },
    'expo-router': { router: { back: () => {} }, useLocalSearchParams: () => ({ id: 'video-item' }) },
    'react-native-safe-area-context': { SafeAreaView: 'SafeAreaView' },
    'lucide-react-native': { Bookmark: 'Bookmark', ExternalLink: 'ExternalLink', Headphones: 'Headphones' },
    '@/hooks/useLibraryResource': { useLibraryResource: () => ({ data: { canRead, body: canRead ? body : null, chapters: canRead ? chapters : [], item: { id: 'video-item', creditCost: 1 }, access: { hasPlus: false, unlockedIds: [], credits: 0 } } }) },
    '@/components/library/LibraryBookmarkButton': { LibraryBookmarkButton: 'LibraryBookmarkButton' },
    '@/components/library/RelatedLibraryItems': { RelatedLibraryItems: 'RelatedLibraryItems' },
    '@/hooks/useTabBarPadding': { useTabBarPadding: () => 80 },
    '@/db/hooks': {
      useLibraryItem: () => ({ id: 'video-item', title: 'Video article', format, body, durationLabel: '5 min', heroImageUrl: 'https://media.example.test/manual-cover.jpg' }),
      useLibraryChapters: () => chapters,
      useTherapist: () => ({ name: 'Author' }),
    },
  };
  for (const name of ['SubHeader', 'IconButton', 'SectionTitle', 'Eyebrow']) {
    modules[`@/components/ui/${name}`] = { [name]: name };
  }
  function load(source) {
    const exports = {};
    new Function('require', 'exports', source)((name) => {
      assert.ok(name in modules, `Unexpected dependency: ${name}`);
      return modules[name];
    }, exports);
    return exports;
  }
  modules['@/components/library/MarkdownBody'] = load(sources[0]);
  modules['./MarkdownBody'] = modules['@/components/library/MarkdownBody'];
  modules['@/components/library/LibraryContent'] = load(sources[3]);
  return { tree: load(sources[format === 'article' ? 2 : 1]).default(), videoSources };
}

function flatten(node) {
  if (!node || typeof node !== 'object') return [node];
  return [node, ...[node.props?.children].flat(Infinity).flatMap(flatten)];
}

const url = 'https://media.example.test/library/video/lesson.mp4';
const body = `## Introduction\n\nBefore the video.\n\n<video src="${url}" controls width="100%"></video>\n\nAfter the video.`;

for (const format of ['video', 'article']) {
  test(`${format} details render the CMS body and its embedded native video`, () => {
    const { tree, videoSources } = renderScreen(format, body);
    assert.deepEqual(videoSources, [url], 'The embedded body video must reach the native player');
    const nodes = flatten(tree);
    assert.ok(nodes.includes('Before the video.'));
    assert.ok(nodes.includes('After the video.'));
    assert.equal(nodes.find((node) => node?.type === 'VideoView').props.nativeControls, true);
  });
}

test('video body coexists with chapters and absent body does not create a player', () => {
  const chapters = [{ id: 'chapter-1', title: 'First chapter', startLabel: '0:00' }];
  const populated = renderScreen('video', body, chapters);
  assert.deepEqual(populated.videoSources, [url]);
  assert.ok(flatten(populated.tree).includes('First chapter'));
  const empty = renderScreen('video', undefined, chapters);
  assert.deepEqual(empty.videoSources, []);
  assert.ok(flatten(empty.tree).includes('First chapter'));
});

test('three embedded videos load their own native start frames and never receive the item cover', () => {
  const urls = ['first', 'second', 'third'].map((name) => `https://media.example.test/${name}.mp4`);
  const { tree, videoSources } = renderScreen('video', urls.map((url) => `<video src="${url}"></video>`).join('\n'));
  assert.deepEqual(videoSources, urls);
  const players = flatten(tree).filter((node) => node?.type === 'VideoView');
  assert.equal(players.length, 3);
  players.forEach((player, index) => {
    assert.equal(player.props.player.url, urls[index]);
    assert.equal(player.props.poster, undefined);
  });
  assert.equal(flatten(tree).some((node) => node?.type === 'Image' && node.props.source?.uri === 'https://media.example.test/manual-cover.jpg'), false);
});

for (const format of ['video', 'article']) {
  test(`${format} locked details never render paid body or chapters`, () => {
    const { tree, videoSources } = renderScreen(format, body, [{ id: 'one', title: 'Private chapter' }], false);
    assert.deepEqual(videoSources, []);
    assert.equal(flatten(tree).includes('Before the video.'), false);
    assert.equal(flatten(tree).includes('Private chapter'), false);
    assert.ok(flatten(tree).includes('Ontgrendelen'));
  });
}
