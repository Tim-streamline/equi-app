import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('library video items do not render a dummy video player', async () => {
  const source = await readFile(
    new URL('../app/(tabs)/library/video/[id].tsx', import.meta.url),
    'utf8',
  );

  assert.doesNotMatch(source, /<LinearGradient/);
  assert.doesNotMatch(source, /logo-horse-white\.png/);
  assert.doesNotMatch(source, />0:00</);
});

test('library articles do not render dummy media above their body', async () => {
  const source = await readFile(
    new URL('../app/(tabs)/library/article/[id].tsx', import.meta.url),
    'utf8',
  );

  assert.doesNotMatch(source, /logo-horse-white\.png/);
  assert.doesNotMatch(source, /contentContainerStyle=\{\{ padding: 20,/);
  assert.match(source, /contentContainerStyle=\{\{ width: '100%', paddingBottom: padBottom \}\}/);
  assert.match(source, /<View className="px-5">[\s\S]*<Eyebrow/);
  assert.match(source, /<LibraryContent key=\{id\} itemId=/);
  const content = await readFile(new URL('../components/library/LibraryContent.tsx', import.meta.url), 'utf8');
  assert.match(content, /<View className="w-full px-5 pt-5"><MarkdownBody markdown=\{data.body\}/);
});

test('embedded markdown videos fill the available markdown width', async () => {
  const source = await readFile(
    new URL('../components/library/MarkdownBody.tsx', import.meta.url),
    'utf8',
  );

  assert.match(source, /className="[^"]*w-full[^"]*aspect-video/);
});
