import { useCallback } from 'react';
import { useFocusEffect } from 'expo-router';
import { useDb } from '@/db/provider';
import { accountSession } from '@/lib/account-session';
import { captureException } from '@/lib/error-tracking';

const newId = () => typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); });

/** Record only opened, readable content; queued writes work offline. */
export function useLibraryViewed(itemId: string, readable: boolean) {
  const { powersync, currentUserId, isLoggedIn } = useDb();
  useFocusEffect(useCallback(() => {
    if (!readable || !currentUserId || !isLoggedIn) return;
    let cancelled = false;
    const revision = accountSession.revision;
    void powersync.writeTransaction(async tx => {
      if (cancelled || accountSession.revision !== revision) return;
      const row = await tx.getOptional<{id: string}>('SELECT id FROM library_progress WHERE user_id = ? AND item_id = ?', [currentUserId, itemId]);
      if (cancelled || accountSession.revision !== revision) return;
      const now = new Date().toISOString();
      if (row) await tx.execute('UPDATE library_progress SET last_viewed_at = ? WHERE id = ?', [now, row.id]);
      else await tx.execute('INSERT INTO library_progress (id, user_id, item_id, last_viewed_at, progress, position_sec, completed) VALUES (?,?,?,?,0,0,0)', [newId(), currentUserId, itemId, now]);
    }).catch(error => captureException(error));
    return () => { cancelled = true; };
  }, [powersync, currentUserId, isLoggedIn, itemId, readable]));
}
