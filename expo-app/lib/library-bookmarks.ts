export type BookmarkSnapshot = {
  itemIds: string[] | null;
  pendingIds: string[];
  error: string | null;
};
type Request = <T>(path: string, method?: string) => Promise<T>;

/** One user-scoped source for list/detail state. Reads cannot overwrite newer mutations. */
export function createLibraryBookmarkStore(request: Request) {
  let snapshot: BookmarkSnapshot = { itemIds: null, pendingIds: [], error: null };
  const listeners = new Set<() => void>();
  let revision = 0;
  let read: Promise<void> | null = null;
  const publish = (next: BookmarkSnapshot) => { snapshot = next; listeners.forEach(listener => listener()); };
  return {
    getSnapshot: () => snapshot,
    subscribe(listener: () => void) { listeners.add(listener); return () => { listeners.delete(listener); }; },
    refresh() {
      if (read) return read;
      const version = revision;
      read = (async () => {
        try {
          const data = await request<{ itemIds: string[] }>('/bookmarks');
          if (version === revision && snapshot.pendingIds.length === 0) publish({ ...snapshot, itemIds: data.itemIds, error: null });
        } catch (error) {
          if (version === revision) publish({ ...snapshot, error: error instanceof Error ? error.message : 'Bewaren niet beschikbaar.' });
        } finally { read = null; }
      })();
      return read;
    },
    async toggle(itemId: string) {
      if (!snapshot.itemIds || snapshot.pendingIds.includes(itemId)) return;
      const wasSaved = snapshot.itemIds.includes(itemId);
      const setSaved = (ids: string[], saved: boolean) => saved ? [...new Set([...ids, itemId])] : ids.filter(id => id !== itemId);
      revision++;
      publish({ itemIds: setSaved(snapshot.itemIds, !wasSaved), pendingIds: [...snapshot.pendingIds, itemId], error: null });
      try {
        const data = await request<{ bookmarked: boolean }>(`/${encodeURIComponent(itemId)}/bookmark`, wasSaved ? 'DELETE' : 'PUT');
        publish({ ...snapshot, itemIds: setSaved(snapshot.itemIds ?? [], data.bookmarked) });
      } catch (error) {
        publish({ ...snapshot, itemIds: setSaved(snapshot.itemIds ?? [], wasSaved) });
        throw error;
      } finally {
        revision++;
        publish({ ...snapshot, pendingIds: snapshot.pendingIds.filter(id => id !== itemId) });
      }
    },
  };
}
