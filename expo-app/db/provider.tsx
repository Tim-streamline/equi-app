import { retrySyncUploads } from '@/db/retry-sync';
import { reportSyncFailure } from '@/lib/sync-errors';
// Provides the PowerSync database to the rest of the app via @powersync/react's
// PowerSyncContext, plus an auth-aware wrapper (DbContext) that tracks the
// logged-in user + sync status. Screens consume data through useQuery (see
// db/hooks.ts) directly against the synced views — there is no in-memory
// mirror.

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { ActivityIndicator, View } from 'react-native';
import {
  type AbstractPowerSyncDatabase,
  type SyncStatus as PsSyncStatus,
} from '@powersync/react-native';
import { PowerSyncContext } from '@powersync/react';

import { accountSession } from '@/lib/account-session';
import { captureException } from '@/lib/error-tracking';
import { getNativeDatabase, connectNativeDatabase, replaceNativeDatabase } from './native-runtime';
import { hasPendingSyncReset, resetSyncKey } from './reset-sync';
import { clearLocalSyncData } from './reset-local-data';
import { useSyncReset } from './use-sync-reset';
import { SyncResetBoundary } from '@/components/sync/SyncResetBoundary';
import { LaravelConnector } from './connector';
import { refreshSubscriptionConnection } from './refresh-subscription';
import {
  clearCredentials,
  deviceCredentials,
  loadCredentials,
  login as loginRequest,
  register as registerRequest,
  completeRegistration as completeRegistrationRequest,
  type RegistrationInput,
  type RegistrationChallenge,
  type RegistrationCompletion,
  revokeDeviceToken,
  saveCredentials,
  type LoginResponse,
} from './auth';

type SyncStatus = 'idle' | 'connecting' | 'syncing' | 'connected' | 'error';

// Collapse PowerSync's rich SyncStatus into the coarse state the UI cares about.
// Order matters: an active up/download outranks a plain connection, and any
// transfer error outranks "connected".
function mapStatus(s: PsSyncStatus): Exclude<SyncStatus, 'idle'> {
  const flow = s.dataFlowStatus;
  if (flow?.downloadError || flow?.uploadError) return 'error';
  if (s.connected) {
    return flow?.downloading || flow?.uploading ? 'syncing' : 'connected';
  }
  if (s.connecting) return 'connecting';
  return 'error'; // requested a connection but neither connected nor connecting → offline
}

type DbContextValue = {
  powersync: AbstractPowerSyncDatabase;
  isLoggedIn: boolean;
  isConnected: boolean;
  syncStatus: SyncStatus;
  currentUserId: string | null;
  selectedHorseId: string | null;
  selectHorse: (id: string) => void;
  login: (email: string, password: string) => Promise<void>;
  register: (input: RegistrationInput) => Promise<RegistrationChallenge>;
  completeRegistration: (input: RegistrationCompletion) => Promise<void>;
  logout: () => Promise<void>;
  refreshSubscriptionAccess: (signal: AbortSignal) => Promise<void>;
  retryUploads: () => Promise<void>;
  retryingUploads: boolean;
  resetSync: () => Promise<void>;
};

const DbContext = createContext<DbContextValue | null>(null);

export function DbProvider({ children }: { children: ReactNode }) {
  const [powersync, setPowersync] = useState<AbstractPowerSyncDatabase | null>(null);
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [syncStatus, setSyncStatus] = useState<SyncStatus>('idle');
  const [retryingUploads, setRetryingUploads] = useState(false);
  const [currentUserId, setCurrentUserId] = useState<string | null>(null);
  const connectedRef = useRef(false);
  const [selectedHorseId, selectHorse] = useState<string | null>(null);
  const databaseRef = useRef<AbstractPowerSyncDatabase | null>(null);
  const reset = useSyncReset({
    getDatabase: () => {
      if (!databaseRef.current) throw new Error('Database not ready');
      return databaseRef.current;
    },
    prepare: (userId) => {
      const connector = new LaravelConnector();
      return {
        markerKey: resetSyncKey(userId), connector,
        fetchCredentials: (signal: AbortSignal) => connector.fetchCredentials(signal),
        replaceDatabase: async (old: AbstractPowerSyncDatabase) => {
          connectedRef.current = false;
          const next = await replaceNativeDatabase(old);
          databaseRef.current = next;
          return next;
        },
        clearLocalData: () => clearLocalSyncData(userId),
      };
    },
    activate: (db) => {
      connectedRef.current = true;
      selectHorse(null);
      setPowersync(db);
      setSyncStatus(mapStatus(db.currentStatus));
    },
  });
  const resetSync = useCallback(() => {
    if (!currentUserId) return Promise.reject(new Error('Meld je opnieuw aan.'));
    return reset.run(currentUserId);
  }, [currentUserId, reset.run]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const ps = await getNativeDatabase();
        if (cancelled) return;

        databaseRef.current = ps;
        const creds = await loadCredentials();
        if (creds) {
          setIsLoggedIn(true);
          setCurrentUserId(creds.userId);
          if (await hasPendingSyncReset(resetSyncKey(creds.userId))) {
            await reset.run(creds.userId, true);
            return;
          }
          setPowersync(ps);
          connectToBackend(ps);
        } else setPowersync(ps);
      } catch (err) {
        console.error('[provider] boot failed', err);
        captureException(err, { tags: { operation: 'database.boot' } });
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => powersync?.registerListener({ statusChanged: status => {
    if (!connectedRef.current || databaseRef.current !== powersync) return;
    setSyncStatus(mapStatus(status));
    if (status.dataFlowStatus?.uploadError) reportSyncFailure(status.dataFlowStatus.uploadError, 'sync.upload');
    if (status.dataFlowStatus?.downloadError) reportSyncFailure(status.dataFlowStatus.downloadError, 'sync.download');
  } }), [powersync]);

  const connectToBackend = useCallback(async (ps: AbstractPowerSyncDatabase) => {
    if (connectedRef.current) return;
    connectedRef.current = true;
    setSyncStatus('connecting');
    try {
      await connectNativeDatabase(ps);
      // Seed from the current snapshot; statusChanged drives it from here on.
      setSyncStatus(mapStatus(ps.currentStatus));
    } catch (err) {
      console.error('[provider] connect failed', err);
      captureException(err, { tags: { operation: 'database.connect' } });
      setSyncStatus('error');
      connectedRef.current = false;
    }
  }, []);

  const login = useCallback(
    async (email: string, password: string) => {
      const pendingUser = await accountSession.transition(async () => {
        if (!powersync) throw new Error('Database not ready');
        const res: LoginResponse = await loginRequest(email, password);
        await saveCredentials(deviceCredentials(res));
        setIsLoggedIn(true);
        setCurrentUserId(String(res.user.id));
        const id = String(res.user.id);
        if (await hasPendingSyncReset(resetSyncKey(id))) return id;
        await connectToBackend(powersync);
        return null;
      });
      if (pendingUser) await reset.run(pendingUser, true);
    },
    [powersync, connectToBackend, reset.run],
  );

  const retryUploads = useCallback(async () => {
    if (!powersync || !currentUserId) throw new Error('Meld je opnieuw aan.');
    setRetryingUploads(true);
    connectedRef.current = true;
    try { await retrySyncUploads(powersync, new LaravelConnector()); }
    finally { setRetryingUploads(false); }
  }, [powersync, currentUserId]);

  const register = registerRequest;
  const refreshSubscriptionAccess = useCallback(async (signal: AbortSignal) => {
    if (!powersync || !currentUserId) throw new Error('Meld je opnieuw aan.');
    const connector = new LaravelConnector();
    connectedRef.current = true;
    await refreshSubscriptionConnection(powersync, connector, signal => connector.fetchCredentials(signal), signal);
  }, [powersync, currentUserId]);
  const completeRegistration = useCallback((input: RegistrationCompletion) => accountSession.transition(async () => {
    if (!powersync) throw new Error('Database not ready');
    const res = await completeRegistrationRequest(input);
    await saveCredentials(deviceCredentials(res));
    setCurrentUserId(String(res.user.id));
    selectHorse(null);
    setIsLoggedIn(true);
    await connectToBackend(powersync);
  }), [powersync, connectToBackend]);

  const logout = useCallback(() => accountSession.transition(async () => {
    let db = databaseRef.current;
    if (!db) return;
    // Recovery can have replaced (or closed) the handle still in React state.
    // Reopen it before signing out, leaving a usable empty database for login.
    if (db.closed) {
      db = await replaceNativeDatabase(db);
      databaseRef.current = db;
    }
    try {
      const { dashboardRequest, deviceTimezone } = await import('@/hooks/useHorseDashboard');
      await dashboardRequest('/api/notifications/push-token', { token: null, timezone: deviceTimezone() });
    } catch (error) {
      if (__DEV__) console.warn('[notifications] Could not unregister this device during logout.', error);
    }
    // Keep stream subscribers mounted until disconnect completes. Unmounting
    // first changes subscriptions and can start a competing stream restart.
    setSyncStatus('idle');
    connectedRef.current = false;
    try {
      await db.disconnectAndClear();
    } catch (err) {
      if (reset.state.error) throw err;
      if (__DEV__) console.warn('[provider] disconnect failed', err);
    }
    // Sign this device out on the server too; offline, the unused token expires on its own.
    // Unreadable secure storage must still leave the app signed out.
    const creds = await loadCredentials().catch(() => null);
    if (creds && 'deviceToken' in creds) void revokeDeviceToken(creds.deviceToken);
    try {
      await clearCredentials();
    } catch (err) {
      captureException(err, { tags: { operation: 'auth.logout' } });
    }
    setIsLoggedIn(false);
    setPowersync(db);
    setCurrentUserId(null);
    selectHorse(null);
  }), [reset.state.error]);

  const value = useMemo<DbContextValue | null>(() => {
    if (!powersync) return null;
    return {
      powersync,
      isLoggedIn,
      isConnected: syncStatus === 'connected',
      syncStatus,
      currentUserId,
      selectedHorseId,
      selectHorse,
      login,
      register,
      completeRegistration,
      logout,
      refreshSubscriptionAccess,
      retryUploads, retryingUploads, resetSync,
    };
  }, [powersync, isLoggedIn, syncStatus, currentUserId, selectedHorseId, login, register, completeRegistration, logout, refreshSubscriptionAccess, retryUploads, retryingUploads, resetSync]);

  if (!powersync || !value) {
    return (
      <SyncResetBoundary state={reset.state} retry={reset.retry} onSignOut={() => void reset.signOut(logout)}><View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#FBF8F3' }}>
        <ActivityIndicator color="#18BAB0" />
      </View></SyncResetBoundary>
    );
  }

  return (
    <SyncResetBoundary state={reset.state} retry={reset.retry} onSignOut={() => void reset.signOut(logout)}><DbContext.Provider value={value}>
      <PowerSyncContext.Provider value={powersync}>
        {children}
      </PowerSyncContext.Provider>
    </DbContext.Provider></SyncResetBoundary>
  );
}

export function useDb(): DbContextValue {
  const ctx = useContext(DbContext);
  if (!ctx) throw new Error('useDb must be used inside <DbProvider>');
  return ctx;
}
