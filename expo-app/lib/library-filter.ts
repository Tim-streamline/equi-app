import type { LibraryAccess } from './library';
export type LibraryFilterItem = {
  id: string;
  title?: string | null;
  description?: string | null;
  format?: string;
  creditCost?: number;
  isPlus?: boolean | number;
};

export type LibraryItemCategoryLink = {
  itemId: string;
  categoryId: string;
};

export type CreditFilter = 'free' | '1' | '2' | '3' | '4+';
export const CREDIT_FILTERS: { id: CreditFilter; label: string }[] = [
  { id: 'free', label: 'Gratis' }, { id: '1', label: '1 credit' },
  { id: '2', label: '2 credits' }, { id: '3', label: '3 credits' }, { id: '4+', label: '4+ credits' },
];
export const LIBRARY_FORMATS = ['article', 'video', 'audio', 'podcast', 'course', 'program'];
export const PERSONAL_FILTERS = [
  { id: 'saved', label: 'Opgeslagen' }, { id: 'unlocked', label: 'Ontgrendeld' },
  { id: 'recent', label: 'Recent bekeken' }, { id: 'unseen', label: 'Nog niet bekeken' },
] as const;
export type PersonalFilter = typeof PERSONAL_FILTERS[number]['id'];
export type LibraryFilters = {
  personal?: readonly PersonalFilter[];
  viewed?: readonly { itemId: string; lastViewedAt: string | null }[];
  categoryGroups?: Readonly<Record<string, string>>;
  now?: number;
  savedOnly?: boolean;
  savedIds?: readonly string[];
  formats?: readonly string[];
  credits?: readonly CreditFilter[];
  accessibleOnly?: boolean;
  excludeFree?: boolean;
  access?: LibraryAccess | null;
};

export const ALL_LIBRARY_FILTER_ID = 'all';

export function toggleLibraryCategory(
  activeCategoryIds: readonly string[],
  categoryId: string,
): string[] {
  if (categoryId === ALL_LIBRARY_FILTER_ID) return [];

  const next = new Set(activeCategoryIds);
  if (next.has(categoryId)) {
    next.delete(categoryId);
  } else {
    next.add(categoryId);
  }

  return [...next];
}

function normalizeSearchText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase()
    .trim();
}

export function filterLibraryItems<T extends LibraryFilterItem>(
  items: readonly T[],
  itemCategories: readonly LibraryItemCategoryLink[],
  activeCategoryIds: readonly string[],
  searchQuery: string,
  filters: LibraryFilters = {},
): T[] {
  const normalizedQuery = normalizeSearchText(searchQuery);
  const activeCategoryIdSet = new Set(activeCategoryIds);
  const groupSelections = new Map<string, Set<string>>();
  for (const id of activeCategoryIdSet) {
    const group = filters.categoryGroups?.[id] ?? 'legacy';
    if (!groupSelections.has(group)) groupSelections.set(group, new Set());
    groupSelections.get(group)!.add(id);
  }
  const itemTags = new Map<string, Set<string>>();
  for (const link of itemCategories) {
    if (!itemTags.has(link.itemId)) itemTags.set(link.itemId, new Set());
    itemTags.get(link.itemId)!.add(link.categoryId);
  }
  const viewed = new Map((filters.viewed ?? []).map(row => [row.itemId, Date.parse(row.lastViewedAt ?? '')]));
  const now = filters.now ?? Date.now();
  const result = items.filter((item) => {
    if ([...groupSelections.values()].some(ids => ![...ids].some(id => itemTags.get(item.id)?.has(id)))) return false;
    if (filters.personal?.length && !filters.personal.some(filter => {
      switch (filter) {
        case 'saved': return filters.savedIds?.includes(item.id);
        case 'unlocked': return filters.access?.unlockedIds.includes(item.id);
        case 'recent': return (viewed.get(item.id) ?? 0) >= now - 30 * 24 * 60 * 60 * 1000;
        case 'unseen': return !Number.isFinite(viewed.get(item.id));
      }
    })) return false;
    if (filters.savedOnly && !filters.savedIds?.includes(item.id)) return false;
    if (filters.formats?.length && !filters.formats.includes(item.format ?? 'article')) return false;
    const price = Number(item.creditCost) || 0;
    if (filters.excludeFree && !item.isPlus && price === 0) return false;
    if (filters.credits?.length && (item.isPlus || !filters.credits.some(band =>
      band === 'free' ? price === 0 : band === '4+' ? price >= 4 : price === Number(band)))) return false;
    if (filters.accessibleOnly && (!filters.access || !(filters.access.allowedIds ? filters.access.allowedIds.includes(item.id) : filters.access.unlockedIds.includes(item.id) || (item.isPlus ? filters.access.hasPlus : price === 0)))) return false;
    if (!normalizedQuery) return true;

    return normalizeSearchText(`${item.title ?? ''} ${item.description ?? ''}`)
      .includes(normalizedQuery);
  });
  return filters.personal?.includes('recent')
    ? result.sort((a, b) => (viewed.get(b.id) || 0) - (viewed.get(a.id) || 0)) : result;
}
