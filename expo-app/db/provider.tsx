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
  PowerSyncDatabase,
  type AbstractPowerSyncDatabase,
} from '@powersync/react-native';
import { PowerSyncContext } from '@powersync/react';
import { OPSqliteOpenFactory } from '@powersync/op-sqlite';

import { AppSchema } from './powersync-schema';
import { LaravelConnector } from './connector';
import {
  clearCredentials,
  loadCredentials,
  login as loginRequest,
  saveCredentials,
  type LoginResponse,
} from './auth';

type SyncStatus = 'idle' | 'connecting' | 'syncing' | 'connected' | 'error';

type DbContextValue = {
  powersync: AbstractPowerSyncDatabase;
  isLoggedIn: boolean;
  isConnected: boolean;
  syncStatus: SyncStatus;
  currentUserId: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
};

const DbContext = createContext<DbContextValue | null>(null);

export function DbProvider({ children }: { children: ReactNode }) {
  const [powersync, setPowersync] = useState<AbstractPowerSyncDatabase | null>(null);
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [syncStatus, setSyncStatus] = useState<SyncStatus>('idle');
  const [currentUserId, setCurrentUserId] = useState<string | null>(null);
  const connectedRef = useRef(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const factory = new OPSqliteOpenFactory({ dbFilename: 'equinova.db' });
        const ps = new PowerSyncDatabase({
          schema: AppSchema,
          database: factory,
          logger: console as any,
        });
        await ps.init();
        if (cancelled) return;
        setPowersync(ps);

        const creds = await loadCredentials();
        if (creds) {
          setIsLoggedIn(true);
          setCurrentUserId(creds.userId);
          connectToBackend(ps);
        }
      } catch (err) {
        console.error('[provider] boot failed', err);
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const connectToBackend = useCallback(async (ps: AbstractPowerSyncDatabase) => {
    if (connectedRef.current) return;
    connectedRef.current = true;
    setSyncStatus('connecting');
    try {
      await ps.connect(new LaravelConnector());
      setSyncStatus('connected');
    } catch (err) {
      console.error('[provider] connect failed', err);
      setSyncStatus('error');
      connectedRef.current = false;
    }
  }, []);

  const login = useCallback(
    async (email: string, password: string) => {
      if (!powersync) throw new Error('Database not ready');
      const res: LoginResponse = await loginRequest(email, password);
      await saveCredentials({
        email,
        password,
        userId: String(res.user.id),
        endpoint: res.endpoint,
      });
      setIsLoggedIn(true);
      setCurrentUserId(String(res.user.id));
      await connectToBackend(powersync);
    },
    [powersync, connectToBackend],
  );

  const logout = useCallback(async () => {
    if (!powersync) return;
    setSyncStatus('idle');
    connectedRef.current = false;
    try {
      await powersync.disconnectAndClear();
    } catch (err) {
      if (__DEV__) console.warn('[provider] disconnect failed', err);
    }
    await clearCredentials();
    setIsLoggedIn(false);
    setCurrentUserId(null);
  }, [powersync]);

  const value = useMemo<DbContextValue | null>(() => {
    if (!powersync) return null;
    return {
      powersync,
      isLoggedIn,
      isConnected: syncStatus === 'connected',
      syncStatus,
      currentUserId,
      login,
      logout,
    };
  }, [powersync, isLoggedIn, syncStatus, currentUserId, login, logout]);

  if (!powersync || !value) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#FBF8F3' }}>
        <ActivityIndicator color="#18BAB0" />
      </View>
    );
  }

  return (
    <DbContext.Provider value={value}>
      <PowerSyncContext.Provider value={powersync}>
        {children}
      </PowerSyncContext.Provider>
    </DbContext.Provider>
  );
}

export function useDb(): DbContextValue {
  const ctx = useContext(DbContext);
  if (!ctx) throw new Error('useDb must be used inside <DbProvider>');
  return ctx;
}
