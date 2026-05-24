// Web build: PowerSync's OP-SQLite adapter is native-only. We expose the
// same DbProvider shape so screens compile, but rendering a notice instead
// of starting PowerSync. To use the app on web hook up @powersync/web here
// or fall back to the old AsyncStorage persister.

import { createContext, useContext, type ReactNode } from 'react';
import { Text, View } from 'react-native';

type DbContextValue = {
  isLoggedIn: boolean;
  isConnected: boolean;
  syncStatus: 'idle';
  currentUserId: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  powersync: never;
};

const DbContext = createContext<DbContextValue | null>(null);

const value: DbContextValue = {
  isLoggedIn: false,
  isConnected: false,
  syncStatus: 'idle',
  currentUserId: null,
  login: async () => {},
  logout: async () => {},
  powersync: undefined as never,
};

export function DbProvider({ children: _children }: { children: ReactNode }) {
  return (
    <DbContext.Provider value={value}>
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24, backgroundColor: '#FBF8F3' }}>
        <Text style={{ fontSize: 18, fontWeight: '700', marginBottom: 8 }}>EquiNova</Text>
        <Text style={{ textAlign: 'center', color: '#4b5b5b' }}>
          PowerSync sync is mobile-only in this build. Open the app on iOS or Android via a development build to use it.
        </Text>
      </View>
    </DbContext.Provider>
  );
}

export function useDb(): DbContextValue {
  const ctx = useContext(DbContext);
  if (!ctx) throw new Error('useDb must be used inside <DbProvider>');
  return ctx;
}
