import { useCallback, useRef, useState } from 'react';
import { useQuery } from '@powersync/react';
import { useDb } from '@/db/provider';
import {
  readHomePreferences, saveHomePreference,
  type HomePreferenceAction, type StoredHomePreferences,
} from '@/lib/home-preferences';

export function useHomePreferences() {
  const { powersync, currentUserId } = useDb();
  const { data, isLoading } = useQuery<StoredHomePreferences>(
    'SELECT * FROM user_home_preferences WHERE id = ?', [currentUserId ?? ''],
  );
  // Do not briefly show a previous account's preference while a query switches.
  const row = data.find((item) => item.id === currentUserId);
  const preferences = readHomePreferences(row);
  const [saving, setSaving] = useState(false);
  const busy = useRef(false);
  const save = useCallback(async (action: HomePreferenceAction) => {
    if (busy.current) return;
    busy.current = true;
    setSaving(true);
    try {
      await saveHomePreference(powersync, currentUserId ?? '', action);
    } finally {
      busy.current = false;
      setSaving(false);
    }
  }, [powersync, currentUserId]);

  return { preferences, ready: !!currentUserId && !isLoading, saving, save };
}
