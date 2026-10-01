import { useEffect, useState } from 'react';
import { AppState } from 'react-native';
import { useQuery } from '@powersync/react';
import { useDb } from '@/db/provider';
import { activeLibraryGrants, type LibraryGrant } from '@/lib/library-sync';

export function useLibraryAccessRecords() {
  const { currentUserId, isLoggedIn } = useDb();
  const { data, isLoading } = useQuery<LibraryGrant>(
    'SELECT user_id, item_id, reason, expires_at FROM library_item_access WHERE user_id = ? ORDER BY item_id',
    [isLoggedIn ? currentUserId : null],
  );
  // Time-based access also expires while offline and after background suspension.
  const [now, setNow] = useState(Date.now);
  useEffect(() => {
    const refresh = () => setNow(Date.now());
    const timer = setInterval(refresh, 1000);
    const listener = AppState.addEventListener('change', refresh);
    return () => { clearInterval(timer); listener.remove(); };
  }, []);
  return { grants: isLoggedIn ? activeLibraryGrants(data.filter(row => row.user_id === currentUserId), now) : [], isLoading };
}
