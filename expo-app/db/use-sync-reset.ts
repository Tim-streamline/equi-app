import { useCallback, useRef, useState } from 'react';
import { resetLocalSync, type ResetDatabase } from './reset-sync';

export type SyncResetState = { busy: boolean; detached: boolean; error: string | null; restartRequired: boolean };
const initial: SyncResetState = { busy: false, detached: false, error: null, restartRequired: false };

export function useSyncReset<T extends ResetDatabase>(options: {
  prepare: (userId: string) => Omit<Parameters<typeof resetLocalSync<T>>[0], 'database' | 'onDestructive' | 'onDetached' | 'onRestartRequired'>;
  getDatabase: () => T;
  activate: (database: T) => void;
  exclusive?: (userId: string, work: () => Promise<void>) => Promise<void>;
}) {
  const [state, setState] = useState(initial);
  const busy = useRef(false);
  const destructive = useRef(false);
  const restartRequired = useRef(false);
  const user = useRef<string | null>(null);
  const latest = useRef(options);
  latest.current = options;
  const run = useCallback(async (userId: string, resume = false) => {
    if (busy.current || restartRequired.current) return;
    busy.current = true;
    user.current = userId;
    if (resume) destructive.current = true;
    setState(previous => ({ ...previous, busy: true, error: null }));
    try {
      const work = async () => {
        const database = await resetLocalSync({
          ...latest.current.prepare(userId), database: latest.current.getDatabase(),
          onDestructive: () => { destructive.current = true; },
          onDetached: () => setState(previous => ({ ...previous, detached: true })),
          onRestartRequired: () => {
            restartRequired.current = true;
            setState({ busy: false, detached: true, restartRequired: true,
              error: 'Het herstellen duurt te lang. Start de app opnieuw om verder te gaan.' });
          },
        });
        if (restartRequired.current) return;
        latest.current.activate(database);
      };
      if (latest.current.exclusive) await latest.current.exclusive(userId, work);
      else await work();
      if (restartRequired.current) return;
      destructive.current = false;
      setState(initial);
    } catch (error) {
      // A late rejection must not replace restart recovery with unsafe retry.
      if (!restartRequired.current) {
        if (destructive.current) {
          setState(previous => ({ ...previous, busy: false, error: error instanceof Error ? error.message : 'Het herstellen is nog niet afgerond. Probeer opnieuw.' }));
        } else setState(initial);
      }
      throw error;
    } finally { busy.current = false; }
  }, []);
  const signOut = useCallback(async (logout: () => Promise<void>) => {
    if (busy.current || restartRequired.current) return;
    busy.current = true;
    setState(previous => ({ ...previous, busy: true, error: null }));
    try {
      await logout();
      destructive.current = false;
      setState(initial);
    } catch {
      setState(previous => ({ ...previous, busy: false, error: 'Uitloggen is niet gelukt. Probeer opnieuw.' }));
    } finally { busy.current = false; }
  }, []);
  return { state, run, signOut, retry: () => { if (user.current) void run(user.current).catch(() => {}); } };
}
