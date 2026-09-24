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
export type LibraryFilters = {
  savedOnly?: boolean;
  savedIds?: readonly string[];
  formats?: readonly string[];
  credits?: readonly CreditFilter[];
  accessibleOnly?: boolean;
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
  const categoryItemIds = activeCategoryIdSet.size > 0
    ? new Set(
        itemCategories
          .filter((link) => activeCategoryIdSet.has(link.categoryId))
          .map((link) => link.itemId),
      )
    : null;

  return items.filter((item) => {
    if (categoryItemIds && !categoryItemIds.has(item.id)) return false;
    if (filters.savedOnly && !filters.savedIds?.includes(item.id)) return false;
    if (filters.formats?.length && !filters.formats.includes(item.format ?? 'article')) return false;
    const price = Number(item.creditCost) || 0;
    if (filters.credits?.length && (item.isPlus || !filters.credits.some(band =>
      band === 'free' ? price === 0 : band === '4+' ? price >= 4 : price === Number(band)))) return false;
    if (filters.accessibleOnly && (!filters.access || !(filters.access.hasPlus
      || filters.access.unlockedIds.includes(item.id) || (!item.isPlus && price === 0)))) return false;
    if (!normalizedQuery) return true;

    return normalizeSearchText(`${item.title ?? ''} ${item.description ?? ''}`)
      .includes(normalizedQuery);
  });
}
