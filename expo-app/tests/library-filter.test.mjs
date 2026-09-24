import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
  ALL_LIBRARY_FILTER_ID,
  filterLibraryItems,
  toggleLibraryCategory,
} from '../lib/library-filter.ts';

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

test('Alles clears every category and a category selection switches Alles off', () => {
  assert.equal(ALL_LIBRARY_FILTER_ID, 'all');
  assert.deepEqual(
    toggleLibraryCategory(['aanbevolen', 'kruiden'], ALL_LIBRARY_FILTER_ID),
    [],
  );
  assert.deepEqual(toggleLibraryCategory([], 'hoeven'), ['hoeven']);
  assert.deepEqual(toggleLibraryCategory(['hoeven'], 'kruiden'), ['hoeven', 'kruiden']);
  assert.deepEqual(toggleLibraryCategory(['hoeven'], 'hoeven'), []);
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

test('active Library filters use the app dark green and light cream colors', async () => {
  const [librarySource, chipSource] = await Promise.all([
    readFile(new URL('../app/(tabs)/(pager)/library.tsx', import.meta.url), 'utf8'),
    readFile(new URL('../components/ui/Chip.tsx', import.meta.url), 'utf8'),
  ]);

  assert.match(
    librarySource,
    /variant=\{selected \? 'filterActive' : 'outline'\}/,
  );
  assert.match(
    chipSource,
    /filterActive:\s*\{\s*box:\s*'bg-teal-700',\s*text:\s*'text-canvas'\s*\}/,
  );
});

test('credit bands compose with saved, category, format and search without hiding locked items in Alles', () => {
  const catalog = [
    { id: 'free', title: 'Hooi', format: 'article', creditCost: 0 },
    { id: 'plus', title: 'Hooi Plus', format: 'article', creditCost: 0, isPlus: 1 },
    ...[1, 2, 3, 4, 7].map(n => ({ id: `paid${n}`, title: 'Hooi video', format: 'video', creditCost: n })),
  ];
  const ids = options => filterLibraryItems(catalog, [], [], '', options).map(item => item.id);
  assert.equal(ids({}).length, 7);
  assert.deepEqual(ids({ credits: ['free'] }), ['free']);
  assert.deepEqual(ids({ credits: ['1', '3', '4+'] }), ['paid1', 'paid3', 'paid4', 'paid7']);
  assert.deepEqual(ids({ savedOnly: true, savedIds: ['free', 'paid2'], formats: ['video'], credits: ['2'] }), ['paid2']);
  assert.deepEqual(filterLibraryItems(catalog, [{ itemId: 'paid2', categoryId: 'hay' }], ['hay'], 'Hooi', {
    formats: ['video'], credits: ['2'], savedOnly: true, savedIds: ['paid2'],
  }).map(item => item.id), ['paid2']);
  assert.deepEqual(ids({ accessibleOnly: true, access: { hasPlus: false, unlockedIds: ['paid2'] } }), ['free', 'paid2']);
  assert.equal(ids({ accessibleOnly: true, access: { hasPlus: true, unlockedIds: [] } }).length, 7);
  assert.deepEqual(ids({ accessibleOnly: true, access: null }), []);
});
