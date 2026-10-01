import { useEffect, useRef } from 'react';
import { useDb } from '@/db/provider';
import { useLibraryAccessRecords } from '@/hooks/useLibraryAccessRecords';
import { createLibrarySubscriptions } from '@/lib/library-sync';

export function LibraryContentSync() {
  const { powersync, currentUserId, isLoggedIn } = useDb();
  const { grants, isLoading } = useLibraryAccessRecords();
  const ids = JSON.stringify(grants.map(grant => grant.item_id));
  const latest = useRef<string[]>([]);
  latest.current = !isLoading && isLoggedIn ? JSON.parse(ids) : [];
  const manager = useRef<ReturnType<typeof createLibrarySubscriptions> | null>(null);
  useEffect(() => {
    if (!isLoggedIn || !currentUserId) return;
    const current = createLibrarySubscriptions(powersync, error => console.warn('[library-sync] Subscription failed', error));
    manager.current = current;
    current.reconcile(latest.current);
    // Retry failed registrations without interrupting already active subscriptions.
    const retry = setInterval(() => current.reconcile(latest.current), 15000);
    return () => { clearInterval(retry); current.dispose(); if (manager.current === current) manager.current = null; };
  }, [powersync, currentUserId, isLoggedIn]);
  useEffect(() => { manager.current?.reconcile(latest.current); }, [ids, isLoading, isLoggedIn]);
  return null;
}
