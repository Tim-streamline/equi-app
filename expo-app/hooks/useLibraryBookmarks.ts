import { useCallback, useMemo, useSyncExternalStore } from 'react';
import { useFocusEffect } from 'expo-router';
import { useDb } from '@/db/provider';
import { createLibraryBookmarkStore } from '@/lib/library-bookmarks';
import { libraryRequest } from './useLibraryResource';
import { accountSession } from '@/lib/account-session';

// Discard cached state on identity changes, including logout. Old requests can only
// update their old store, never the next user's state.
let activeIdentity: string | undefined;
let activeStore: ReturnType<typeof createLibraryBookmarkStore> | undefined;
export function useLibraryBookmarks() {
  const { currentUserId } = useDb();
  const identity = `${currentUserId}:${accountSession.revision}`;
  const sessionRevision = accountSession.revision;
  const store = useMemo(() => {
    if (!activeStore || activeIdentity !== identity) {
      activeIdentity = identity;
      activeStore = createLibraryBookmarkStore(<T,>(path: string, method?: string) => {
        if (!currentUserId || accountSession.revision !== sessionRevision) return Promise.reject(new Error('Je sessie is gewijzigd.'));
        return libraryRequest<T>(path, method);
      });
    }
    return activeStore;
  }, [currentUserId, identity, sessionRevision]);
  const state = useSyncExternalStore(store.subscribe, store.getSnapshot, store.getSnapshot);
  useFocusEffect(useCallback(() => {
    if (currentUserId) void store.refresh();
  }, [currentUserId, store]));
  return { ...state, refresh: store.refresh, toggle: store.toggle };
}
