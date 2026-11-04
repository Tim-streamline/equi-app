import { retrySyncUploads } from '@/db/retry-sync';
import { hasPendingSyncReset, resetSyncKey } from '@/db/reset-sync';
import { clearLocalSyncData } from '@/db/reset-local-data';
import { useSyncReset } from '@/db/use-sync-reset';
import { SyncResetBoundary } from '@/components/sync/SyncResetBoundary';
import { SyncResetAccess } from './reset-access';
import { reportSyncFailure } from '@/lib/sync-errors';
import { cancelSyncRequests } from '@/lib/sync-request';
import { disableBrowserPush } from '../lib/browser-push';
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { WASQLiteOpenFactory } from '@powersync/web/umd';
import { PowerSyncDatabase } from './powersync';
import { PowerSyncContext } from '@powersync/react';
import type { SyncStatus as PsSyncStatus } from '@powersync/common';
import { AppSchema } from '@/db/powersync-schema';
import { accountSession } from '@/lib/account-session';
import { captureException } from '../components/error-tracking';
import { getSession, login as signIn, register as signUp, type RegistrationInput, type RegistrationChallenge, type RegistrationCompletion, completeRegistration as confirmSignUp, logout as signOut, forgetSession, type LoginResponse } from './auth';
import { LOCAL_SIGNOUT_KEY, isLocallySignedOut, retryPendingLogout } from './auth';
import { LaravelConnector } from './connector';
import { refreshSubscriptionConnection } from '@/db/refresh-subscription';
import { databaseName } from './database-name';
import { browserTabId } from '@/lib/browser-tab';
import { loginDestination } from './login-destination';
import { registerRefreshProtection } from '../../expo-app/lib/refresh-safety.web';

type SyncStatus = 'idle' | 'connecting' | 'syncing' | 'connected' | 'error';
type DbContextValue = {
  powersync: PowerSyncDatabase; isLoggedIn: boolean; isConnected: boolean;
  syncStatus: SyncStatus; currentUserId: string | null; selectedHorseId: string | null;
  refreshSubscriptionAccess: (signal: AbortSignal) => Promise<void>;
  retryUploads: () => Promise<void>;
  retryingUploads: boolean;
  resetSync: () => Promise<void>;
  selectHorse: (id: string) => void; login: (email: string, password: string) => Promise<void>; register: (input: RegistrationInput) => Promise<RegistrationChallenge>; completeRegistration: (input: RegistrationCompletion) => Promise<void>; logout: () => Promise<void>;
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
  const [retryingUploads, setRetryingUploads] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const active = useRef<{ db: PowerSyncDatabase; userId: string | null; unsubscribe: () => void } | null>(null);
  const channel = useRef<BroadcastChannel | null>(null);
  const mounted = useRef(true);
  const [attempt, setAttempt] = useState(0);
  const resetAccess = useRef(new SyncResetAccess());
  const resetKey = (id: string) => resetSyncKey(id, browserTabId());
  const createDatabase = async (id: string | null) => {
    const tab = browserTabId();
    const flags = { enableMultiTabs: false };
    const db = new PowerSyncDatabase({
      schema: AppSchema,
      database: new WASQLiteOpenFactory({ dbFilename: await databaseName(tab, id), worker: '/powersync/worker/WASQLiteDB.umd.js', flags }),
      flags,
    });
    try { await db.init(); }
    catch (error) { await db.close().catch(() => {}); throw error; }
    const unsubscribe = db.registerListener({ statusChanged: status => {
      if (active.current?.db !== db || !id) return;
      setSyncStatus(mapStatus(status));
      if (status.dataFlowStatus?.uploadError) reportSyncFailure(status.dataFlowStatus.uploadError, 'sync.upload');
      if (status.dataFlowStatus?.downloadError) reportSyncFailure(status.dataFlowStatus.downloadError, 'sync.download');
    } });
    active.current = { db, userId: id, unsubscribe };
    return db;
  };
  const reset = useSyncReset({
    getDatabase: () => {
      if (!active.current) throw new Error('Database not ready');
      return active.current.db;
    },
    prepare: id => {
      const connector = new LaravelConnector(id);
      return {
        markerKey: resetKey(id), connector,
        fetchCredentials: (signal: AbortSignal) => connector.fetchCredentials(signal, true),
        replaceDatabase: async (old: PowerSyncDatabase) => {
          active.current?.unsubscribe();
          await old.close();
          return createDatabase(id);
        },
        clearLocalData: () => clearLocalSyncData(id),
      };
    },
    exclusive: (id, work) => resetAccess.current.exclusive(id, work),
    activate: db => { selectHorse(null); setDatabase(db); setSyncStatus(mapStatus(db.currentStatus)); },
  });
  const resetSync = () => {
    if (!active.current?.userId) return Promise.reject(new Error('Meld je opnieuw aan.'));
    return reset.run(active.current.userId);
  };
  const refreshSelection = useRef({ userId, selectedHorseId });
  refreshSelection.current = { userId, selectedHorseId };
  useEffect(() => registerRefreshProtection({ prepare: async () => {
    const selection = refreshSelection.current;
    if (selection.userId) sessionStorage.setItem('equi-refresh-selection', JSON.stringify(selection));
  } }), []);

  const open = async (session: LoginResponse | null, clearPrevious = true) => {
    setDatabase(null);
    setError(null);
    const previous = active.current;
    active.current = null;
    if (previous) {
      previous.unsubscribe();
      if (clearPrevious && !previous.db.closed) await previous.db.disconnectAndClear();
      await previous.db.close();
    }
    const id = session ? String(session.user.id) : null;
    // Separate account databases prevent a new session from seeing another
    // account's rows. Per-tab files also isolate concurrent sync upload queues.
    await resetAccess.current.enter(id);
    const db = await createDatabase(id);
    try {
      if (!mounted.current) { await db.close(); return; }
      let restoredHorse: string | null = null;
      try {
        const saved = JSON.parse(sessionStorage.getItem('equi-refresh-selection') ?? 'null');
        if (saved?.userId === id && typeof saved.selectedHorseId === 'string') restoredHorse = saved.selectedHorseId;
        sessionStorage.removeItem('equi-refresh-selection');
      } catch { /* A missing refresh selection uses the ordinary default horse. */ }
      setUserId(id); selectHorse(restoredHorse); setSyncStatus(id ? 'connecting' : 'idle');
      if (id && await hasPendingSyncReset(resetKey(id))) return;
      setDatabase(db);
      if (id) void db.connect(new LaravelConnector(id)).catch((cause) => {
        captureException(cause, { tags: { operation: 'database.connect' } });
        if (active.current?.db === db) setSyncStatus('error');
      });
    } catch (cause) {
      await db.close().catch(() => {});
      throw cause;
    }
  };

  useEffect(() => {
    mounted.current = true;
    let changingSession = false;
    void accountSession.transition(async () => {
      try {
        const session = await getSession().catch(cause => { if (isLocallySignedOut()) return null; throw cause; });
        await open(isLocallySignedOut() ? null : session);
      }
      catch (cause) {
        captureException(cause, { tags: { operation: 'database.boot' } });
        if (mounted.current) setError(cause instanceof Error ? cause.message : 'De app kon niet starten.');
      }
    }).then(async () => {
      const id = active.current?.userId;
      if (id && await hasPendingSyncReset(resetKey(id))) await reset.run(id, true);
    }).catch(() => { /* Interrupted resets expose their own recovery screen. */ });
    const changeSession = (welcome = false) => {
      if (changingSession) return;
      changingSession = true;
      forgetSession();
      cancelSyncRequests();
      if (isLocallySignedOut()) void disableBrowserPush(undefined, false).catch(() => {});
      void accountSession.transition(async () => {
        try {
          await open(null);
          if (welcome) window.location.replace('/onboarding/welcome');
          else window.location.reload();
        }
        catch (cause) { setError(String(cause)); }
      });
    };
    const expire = () => { if (active.current?.userId) changeSession(true); };
    window.addEventListener('equinova:session-expired', expire);
    channel.current = new BroadcastChannel('equinova:session');
    channel.current.onmessage = () => changeSession();
    const storage = (event: StorageEvent) => {
      if (event.key === LOCAL_SIGNOUT_KEY && isLocallySignedOut() && active.current?.userId) changeSession(true);
    };
    window.addEventListener('storage', storage);
    const retryLogout = () => { void retryPendingLogout(); };
    window.addEventListener('online', retryLogout);
    const logoutTimer = setInterval(retryLogout, 30_000);
    retryLogout();
    // Revalidate after a suspended tab resumes, before its token expires.
    const focus = () => {
      retryLogout();
      if (isLocallySignedOut()) { if (active.current?.userId) changeSession(true); return; }
      if (active.current?.userId) void getSession(undefined, true).catch(() => setSyncStatus('error'));
    };
    window.addEventListener('focus', focus);
    return () => {
      mounted.current = false;
      window.removeEventListener('equinova:session-expired', expire);
      window.removeEventListener('focus', focus);
      window.removeEventListener('storage', storage);
      window.removeEventListener('online', retryLogout);
      clearInterval(logoutTimer);
      channel.current?.close();
      const current = active.current;
      active.current = null;
      current?.unsubscribe();
      void current?.db.close();
      void resetAccess.current.leave();
    };
    // Database initialization is serialized with all account transitions.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [attempt]);

  const retryUploads = useCallback(async () => {
    const current = active.current;
    if (!current?.userId) throw new Error('Meld je opnieuw aan.');
    setRetryingUploads(true);
    const connector = new LaravelConnector(current.userId);
    try {
      await retrySyncUploads(current.db, {
        fetchCredentials: () => connector.fetchCredentials(undefined, true),
        uploadData: db => connector.uploadData(db),
      });
    }
    finally { if (mounted.current) setRetryingUploads(false); }
  }, []);

  const refreshSubscriptionAccess = useCallback(async (signal: AbortSignal) => {
    const current = active.current;
    if (!current?.userId) throw new Error('Meld je opnieuw aan.');
    const connector = new LaravelConnector(current.userId);
    await refreshSubscriptionConnection(current.db, connector, signal => connector.fetchCredentials(signal, true), signal);
  }, []);

  const login = (email: string, password: string) => accountSession.transition(async () => {
    const destination = loginDestination(window.location.search);
    await signIn(email, password);
    channel.current?.postMessage('changed');
    // Recreate the router and database together under the authenticated session.
    // Keeping this as a document navigation preserves the requested deep link.
    window.location.replace(destination);
  });
  const register = signUp;
  const completeRegistration = (input: RegistrationCompletion) => accountSession.transition(async () => {
    await confirmSignUp(input);
    channel.current?.postMessage('changed');
    window.location.replace('/onboarding/add-horse');
  });
  const logout = () => {
    // Persist intent before any waits, including the account/database queue.
    // Neither backend availability nor push unregistration blocks local sign-out.
    signOut();
    cancelSyncRequests();
    void disableBrowserPush(undefined, false).catch(() => {});
    channel.current?.postMessage('changed');
    return accountSession.transition(() => open(null));
  };
  if (error) return <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, gap: 16 }}>
    <Text accessibilityRole="alert">{error}</Text>
    <Pressable accessibilityRole="button" onPress={() => { setError(null); setAttempt(value => value + 1); }}><Text>Opnieuw proberen</Text></Pressable>
  </View>;
  if (!database) return <SyncResetBoundary state={reset.state} retry={reset.retry} onSignOut={() => void reset.signOut(logout)}><View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}><ActivityIndicator accessibilityLabel="App laden" color="#127A79" /></View></SyncResetBoundary>;
  return <SyncResetBoundary state={reset.state} retry={reset.retry} onSignOut={() => void reset.signOut(logout)}><PowerSyncContext.Provider value={database}><DbContext.Provider value={{ powersync: database, isLoggedIn: !!userId, isConnected: syncStatus === 'connected', syncStatus, retryUploads, retryingUploads, resetSync, currentUserId: userId, selectedHorseId, selectHorse, login, register, completeRegistration, logout, refreshSubscriptionAccess }}>
    {children}
  </DbContext.Provider></PowerSyncContext.Provider></SyncResetBoundary>;
}
export function useDb() {
  const context = useContext(DbContext);
  if (!context) throw new Error('useDb must be used inside DbProvider');
  return context;
}
