import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import { filterLibraryItems } from '../lib/library-filter.ts';

const items = [
  {
    id: 'brandnetel',
    title: 'Brandnetel doseren',
    description: 'Praktische uitleg over kruiden.',
  },
  {
    id: 'hoefbevangenheid',
    title: 'Hoefbevangenheid herkennen',
    description: 'De eerste signalen bij je paard.',
  },
  {
    id: 'lijnzaad',
    title: 'Lijnzaad in zeven dagen',
    description: 'Ondersteuning voor de spijsvertering.',
  },
];

const itemCategories = [
  { itemId: 'brandnetel', categoryId: 'kruiden' },
  { itemId: 'brandnetel', categoryId: 'aanbevolen' },
  { itemId: 'hoefbevangenheid', categoryId: 'hoeven' },
  { itemId: 'lijnzaad', categoryId: 'aanbevolen' },
];

test('shows items belonging to at least one active category', () => {
  assert.deepEqual(
    filterLibraryItems(items, itemCategories, ['kruiden', 'hoeven'], '').map((item) => item.id),
    ['brandnetel', 'hoefbevangenheid'],
  );
});

test('shows every library item when no categories are active', () => {
  assert.deepEqual(
    filterLibraryItems(items, itemCategories, [], '').map((item) => item.id),
    ['brandnetel', 'hoefbevangenheid', 'lijnzaad'],
  );
});

test('searches titles and descriptions case-insensitively', () => {
  assert.deepEqual(
    filterLibraryItems(items, itemCategories, [], 'PAARD').map((item) => item.id),
    ['hoefbevangenheid'],
  );
});

test('combines the category and search filters', () => {
  assert.deepEqual(
    filterLibraryItems(items, itemCategories, ['aanbevolen'], 'lijnzaad').map((item) => item.id),
    ['lijnzaad'],
  );

  assert.deepEqual(
    filterLibraryItems(items, itemCategories, ['kruiden'], 'lijnzaad'),
    [],
  );
});

test('ignores surrounding whitespace and accents in search text', () => {
  assert.deepEqual(
    filterLibraryItems(items, itemCategories, [], '  hoefbevangenhéid  ').map((item) => item.id),
    ['hoefbevangenheid'],
  );
});

test('the Library screen wires search and category controls to the filtered list', async () => {
  const source = await readFile(
    new URL('../app/(tabs)/(pager)/library.tsx', import.meta.url),
    'utf8',
  );

  assert.match(source, /onChangeText=\{setSearchQuery\}/);
  assert.match(source, /onPress=\{\(\) => toggleCategory\(c\.id\)\}/);
  assert.match(
    source,
    /filterLibraryItems\(list, itemCategories, activeCategoryIds, searchQuery\)/,
  );
  assert.match(source, /filteredList\.map/);
});

test('active Library filters use the app dark green and light cream colors', async () => {
  const [librarySource, chipSource] = await Promise.all([
    readFile(new URL('../app/(tabs)/(pager)/library.tsx', import.meta.url), 'utf8'),
    readFile(new URL('../components/ui/Chip.tsx', import.meta.url), 'utf8'),
  ]);

  assert.match(
    librarySource,
    /variant=\{activeCategoryIds\.includes\(c\.id\) \? 'filterActive' : 'outline'\}/,
  );
  assert.match(
    chipSource,
    /filterActive:\s*\{\s*box:\s*'bg-teal-700',\s*text:\s*'text-canvas'\s*\}/,
  );
});
