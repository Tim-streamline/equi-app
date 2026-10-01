/** Owns the item subscriptions for one authenticated database session. */
export type LibrarySubscription = { unsubscribe(): void };
export type LibraryStreamDatabase = {
  syncStream(name: string, parameters: Record<string, string>): {
    subscribe(options: { ttl: number }): Promise<LibrarySubscription>;
  };
};
export function createLibrarySubscriptions(database: LibraryStreamDatabase, onError: (error: unknown) => void) {
  const subscriptions = new Map<string, { handle?: LibrarySubscription }>();
  let disposed = false;
  return {
    reconcile(itemIds: readonly string[]) {
      if (disposed) return;
      const desired = new Set(itemIds);
      for (const [id, entry] of subscriptions) {
        if (!desired.has(id)) { subscriptions.delete(id); entry.handle?.unsubscribe(); }
      }
      for (const id of desired) {
        if (subscriptions.has(id)) continue;
        const entry: { handle?: LibrarySubscription } = {};
        subscriptions.set(id, entry);
        void database.syncStream('library_content', { item_id: id }).subscribe({ ttl: 0 }).then(handle => {
          if (disposed || subscriptions.get(id) !== entry) handle.unsubscribe();
          else entry.handle = handle;
        }).catch(error => {
          if (subscriptions.get(id) === entry) { subscriptions.delete(id); onError(error); }
        });
      }
    },
    dispose() {
      disposed = true;
      for (const entry of subscriptions.values()) entry.handle?.unsubscribe();
      subscriptions.clear();
    },
  };
}

export type LibraryGrant = { user_id: string; item_id: string; reason: string; expires_at: string | null };
export function activeLibraryGrants(rows: readonly LibraryGrant[], now = Date.now()): LibraryGrant[] {
  return rows.filter(row => {
    if (!row.expires_at) return true;
    const iso = row.expires_at.replace(' ', 'T');
    return Date.parse(/(?:Z|[+-]\d{2}:?\d{2})$/.test(iso) ? iso : `${iso}Z`) > now;
  });
}
