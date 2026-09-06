export type StoredHomePreferences = {
  id?: string;
  seasonal_tips_enabled?: boolean | number | string | null;
  dismissed_tip_ids?: string | string[] | null;
};

export type HomePreferences = {
  seasonalTipsEnabled: boolean;
  dismissedTipIds: string[];
};

export function readHomePreferences(row?: StoredHomePreferences | null): HomePreferences {
  let ids: unknown = row?.dismissed_tip_ids ?? [];
  if (typeof ids === 'string') {
    try { ids = JSON.parse(ids); } catch { ids = []; }
  }
  return {
    seasonalTipsEnabled: ![false, 0, '0'].includes(row?.seasonal_tips_enabled as boolean | number | string),
    dismissedTipIds: Array.isArray(ids) ? ids.filter((id): id is string => typeof id === 'string') : [],
  };
}

export function seasonalTipVisible(tip: { id?: string } | null | undefined, preferences: HomePreferences): boolean {
  // Older cached dashboards lack identity; wait for a fresh response so X can
  // always remember the specific tip rather than a month, title, or horse.
  return !!tip?.id && preferences.seasonalTipsEnabled && !preferences.dismissedTipIds.includes(tip.id);
}

type PreferenceTransaction = {
  getOptional<T>(sql: string, parameters: any[]): Promise<T | null>;
  execute(sql: string, parameters: any[]): Promise<unknown>;
};

type PreferenceDatabase = {
  writeTransaction<T>(callback: (tx: PreferenceTransaction) => Promise<T>): Promise<T>;
};

export type HomePreferenceAction =
  | { type: 'dismiss'; tipId: string }
  | { type: 'set-enabled'; enabled: boolean };

export async function saveHomePreference(database: PreferenceDatabase, userId: string, action: HomePreferenceAction) {
  if (!userId) throw new Error('Meld je opnieuw aan om je voorkeuren te bewaren.');
  return database.writeTransaction(async (tx) => {
    const row = await tx.getOptional<StoredHomePreferences>(
      'SELECT * FROM user_home_preferences WHERE id = ?', [userId],
    );
    const current = readHomePreferences(row);
    const next = { ...current };
    if (action.type === 'dismiss') {
      if (!action.tipId) throw new Error('Deze seizoenstip kan nog niet worden verborgen.');
      next.dismissedTipIds = [...new Set([...current.dismissedTipIds, action.tipId])];
    } else {
      next.seasonalTipsEnabled = action.enabled;
      // Explicitly opting back in restores the current tip, including a tip
      // previously hidden with X. Merely revisiting this screen does not reset it.
      if (action.enabled && !current.seasonalTipsEnabled) next.dismissedTipIds = [];
    }
    const values = [Number(next.seasonalTipsEnabled), JSON.stringify(next.dismissedTipIds), userId];
    await tx.execute(row
      ? 'UPDATE user_home_preferences SET seasonal_tips_enabled = ?, dismissed_tip_ids = ? WHERE id = ?'
      : 'INSERT INTO user_home_preferences (seasonal_tips_enabled, dismissed_tip_ids, id) VALUES (?, ?, ?)', values);
    return next;
  });
}
