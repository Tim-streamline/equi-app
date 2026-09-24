import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { PowerSyncDatabase, WASQLiteOpenFactory } from '@powersync/web/umd';
import { PowerSyncContext } from '@powersync/react';
import type { SyncStatus as PsSyncStatus } from '@powersync/common';
import { AppSchema } from '@/db/powersync-schema';
import { accountSession } from '@/lib/account-session';
import { getSession, login as signIn, logout as signOut, forgetSession, type LoginResponse } from './auth';
import { LaravelConnector } from './connector';
import { databaseName } from './database-name';
import { loginDestination } from './login-destination';

type SyncStatus = 'idle' | 'connecting' | 'syncing' | 'connected' | 'error';
type DbContextValue = {
  powersync: PowerSyncDatabase; isLoggedIn: boolean; isConnected: boolean;
  syncStatus: SyncStatus; currentUserId: string | null; selectedHorseId: string | null;
  selectHorse: (id: string) => void; login: (email: string, password: string) => Promise<void>; logout: () => Promise<void>;
};
const DbContext = createContext<DbContextValue | null>(null);
function mapStatus(status: PsSyncStatus): SyncStatus {
  if (status.dataFlowStatus?.downloadError || status.dataFlowStatus?.uploadError) return 'error';
  if (status.connected) return status.dataFlowStatus?.downloading || status.dataFlowStatus?.uploading ? 'syncing' : 'connected';
  return status.connecting ? 'connecting' : 'error';
}
export function DbProvider({ children }: { children: ReactNode }) {
  const [database, setDatabase] = useState<PowerSyncDatabase | null>(null);
  const [userId, setUserId] = useState<string | null>(null);
  const [selectedHorseId, selectHorse] = useState<string | null>(null);
  const [syncStatus, setSyncStatus] = useState<SyncStatus>('idle');
  const [error, setError] = useState<string | null>(null);
  const active = useRef<{ db: PowerSyncDatabase; userId: string | null; unsubscribe: () => void } | null>(null);
  const channel = useRef<BroadcastChannel | null>(null);
  const mounted = useRef(true);
  const [attempt, setAttempt] = useState(0);

  const open = async (session: LoginResponse | null, clearPrevious = true) => {
    setDatabase(null);
    setError(null);
    const previous = active.current;
    active.current = null;
    if (previous) {
      previous.unsubscribe();
      if (clearPrevious) await previous.db.disconnectAndClear();
      await previous.db.close();
    }
    const id = session ? String(session.user.id) : null;
    // Separate account databases prevent a new session from seeing another
    // account's rows. Per-tab files also isolate concurrent sync upload queues.
    let tab = sessionStorage.getItem('equinova:tab');
    if (!tab) { tab = crypto.randomUUID(); sessionStorage.setItem('equinova:tab', tab); }
    const flags = { enableMultiTabs: false };
    const db = new PowerSyncDatabase({
      schema: AppSchema,
      database: new WASQLiteOpenFactory({ dbFilename: await databaseName(tab, id), worker: '/powersync/worker/WASQLiteDB.umd.js', flags }),
      flags,
    });
    try {
      await db.init();
      if (!mounted.current) { await db.close(); return; }
      const unsubscribe = db.registerListener({ statusChanged: status => {
        if (active.current?.db === db && id) setSyncStatus(mapStatus(status));
      } });
      active.current = { db, userId: id, unsubscribe };
      setUserId(id); selectHorse(null); setSyncStatus(id ? 'connecting' : 'idle');
      setDatabase(db);
      if (id) void db.connect(new LaravelConnector(id)).catch(() => {
        if (active.current?.db === db) setSyncStatus('error');
      });
    } catch (cause) {
      await db.close().catch(() => {});
      throw cause;
    }
  };

  useEffect(() => {
    mounted.current = true;
    void accountSession.transition(async () => {
      try { await open(await getSession()); }
      catch (cause) { if (mounted.current) setError(cause instanceof Error ? cause.message : 'De app kon niet starten.'); }
    });
    const expire = () => {
      if (!active.current?.userId) return;
      forgetSession();
      void accountSession.transition(async () => {
        try { await open(null); window.location.replace('/onboarding/welcome'); }
        catch (cause) { setError(String(cause)); }
      });
    };
    window.addEventListener('equinova:session-expired', expire);
    channel.current = new BroadcastChannel('equinova:session');
    channel.current.onmessage = () => {
      forgetSession();
      void accountSession.transition(async () => {
        try { await open(null); window.location.reload(); }
        catch (cause) { setError(String(cause)); }
      });
    };
    // Revalidate after a suspended tab resumes, before its token expires.
    const focus = () => { if (active.current?.userId) void getSession(undefined, true).catch(() => setSyncStatus('error')); };
    window.addEventListener('focus', focus);
    return () => {
      mounted.current = false;
      window.removeEventListener('equinova:session-expired', expire);
      window.removeEventListener('focus', focus);
      channel.current?.close();
      const current = active.current;
      active.current = null;
      current?.unsubscribe();
      void current?.db.close();
    };
    // Database initialization is serialized with all account transitions.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [attempt]);

  const login = (email: string, password: string) => accountSession.transition(async () => {
    const destination = loginDestination(window.location.search);
    await signIn(email, password);
    channel.current?.postMessage('changed');
    // Recreate the router and database together under the authenticated session.
    // Keeping this as a document navigation preserves the requested deep link.
    window.location.replace(destination);
  });
  const logout = () => accountSession.transition(async () => {
    await signOut();
    channel.current?.postMessage('changed');
    await open(null);
  });
  if (error) return <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 16 }}>
    <Text accessibilityRole="alert">{error}</Text>
    <Pressable accessibilityRole="button" onPress={() => { setError(null); setAttempt(value => value + 1); }}><Text>Opnieuw proberen</Text></Pressable>
  </View>;
  if (!database) return <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}><ActivityIndicator accessibilityLabel="App laden" color="#127A79" /></View>;
  return <PowerSyncContext.Provider value={database}><DbContext.Provider value={{ powersync: database, isLoggedIn: !!userId, isConnected: syncStatus === 'connected', syncStatus, currentUserId: userId, selectedHorseId, selectHorse, login, logout }}>
    {children}
  </DbContext.Provider></PowerSyncContext.Provider>;
}
export function useDb() {
  const context = useContext(DbContext);
  if (!context) throw new Error('useDb must be used inside DbProvider');
  return context;
}
