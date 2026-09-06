export type LibraryFilterItem = {
  id: string;
  title?: string | null;
  description?: string | null;
};

export type LibraryItemCategoryLink = {
  itemId: string;
  categoryId: string;
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
    if (!normalizedQuery) return true;

    return normalizeSearchText(`${item.title ?? ''} ${item.description ?? ''}`)
      .includes(normalizedQuery);
  });
}
