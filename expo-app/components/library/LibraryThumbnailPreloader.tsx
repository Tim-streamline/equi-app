import { useEffect } from 'react';
import { AppState } from 'react-native';
import { useLibraryItems } from '@/db/hooks';
import { useDb } from '@/db/provider';
import { preloadLibraryThumbnails } from '@/lib/library-thumbnail-cache';

export function LibraryThumbnailPreloader() {
  const items = useLibraryItems();
  const { isLoggedIn, currentUserId, isConnected } = useDb();
  // Keep the effect stable when query results have identical thumbnail URLs.
  const urls = JSON.stringify([...new Set(items.map(item => item.heroImageUrl)
    .filter((uri): uri is string => typeof uri === 'string' && !!uri))].sort());

  useEffect(() => {
    if (!isLoggedIn) return;
    let cancelled = false;
    let running = false;
    const run = async () => {
      if (cancelled || running || AppState.currentState !== 'active') return;
      running = true;
      try {
        await preloadLibraryThumbnails(JSON.parse(urls),
          () => cancelled || AppState.currentState !== 'active');
      } finally {
        running = false;
      }
    };
    void run();
    const subscription = AppState.addEventListener('change', state => {
      if (state === 'active') void run();
    });
    // PowerSync can stay connected while the media backend is down. Retry
    // independently so thumbnails recover when that backend comes back.
    const interval = setInterval(() => { void run(); }, 60_000);
    return () => {
      cancelled = true;
      clearInterval(interval);
      subscription.remove();
    };
  }, [urls, isLoggedIn, currentUserId, isConnected]);

  return null;
}
