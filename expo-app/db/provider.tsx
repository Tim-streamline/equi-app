// Initializes:
//   1. The PowerSync SQLite database (op-sqlite under the hood)
//   2. A TinyBase store that mirrors PowerSync's SQLite via the
//      PowerSyncPersister in tabular mode
//   3. TinyBase Relationships for downstream UI hooks
//
// PowerSync only connects once we have stored credentials — until the user
// logs in, the TinyBase store is empty and screens that depend on data
// render their empty / loading states.

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
import { createStore, type Store } from 'tinybase';
import { createRelationships } from 'tinybase/relationships';
import { Provider } from 'tinybase/ui-react';
import {
  PowerSyncDatabase,
  type AbstractPowerSyncDatabase,
} from '@powersync/react-native';
import { OPSqliteOpenFactory } from '@powersync/op-sqlite';
// PowerSync's TinyBase persister wraps load/save in a SQLite transaction,
// which collides with PowerSync's own apply transaction (see comment near
// the bridge wiring below). We use a hand-rolled pull bridge instead.
// import { createPowerSyncPersister } from 'tinybase/persisters/persister-powersync';
// import type { Persister } from 'tinybase/persisters';

import { AppSchema, SYNCED_TABLE_IDS } from './powersync-schema';
import { TABLES_SCHEMA, VALUES_SCHEMA } from './schema';
import { setupRelationships } from './relationships';
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
  store: Store;
  powersync: AbstractPowerSyncDatabase;
  isLoggedIn: boolean;
  isConnected: boolean;
  syncStatus: SyncStatus;
  currentUserId: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
};

const DbContext = createContext<DbContextValue | null>(null);

const tabularConfig = {
  mode: 'tabular' as const,
  tables: {
    load: Object.fromEntries(SYNCED_TABLE_IDS.map((id) => [id, id])),
    save: Object.fromEntries(SYNCED_TABLE_IDS.map((id) => [id, id])),
  },
};

function buildStore() {
  return createStore()
    .setTablesSchema(TABLES_SCHEMA as any)
    .setValuesSchema(VALUES_SCHEMA as any);
}

export function DbProvider({ children }: { children: ReactNode }) {
  const [bundle, setBundle] = useState<null | {
    store: Store;
    powersync: AbstractPowerSyncDatabase;
    relationships: ReturnType<typeof createRelationships>;
  }>(null);
  const [isLoggedIn, setIsLoggedIn] = useState(false);
  const [syncStatus, setSyncStatus] = useState<SyncStatus>('idle');
  const [currentUserId, setCurrentUserId] = useState<string | null>(null);
  const connectedRef = useRef(false);

  // 1. Init PowerSync + TinyBase exactly once.
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        console.log('[provider] booting…');
        const factory = new OPSqliteOpenFactory({ dbFilename: 'equinova.db' });
        const powersync = new PowerSyncDatabase({
          schema: AppSchema,
          database: factory,
          logger: console as any,
        });
        await powersync.init();
        console.log('[provider] powersync initialized');

        const store = buildStore();
        // The TinyBase PowerSync persister wraps its load in a SQLite
        // transaction, which clashes with PowerSync's own apply transaction
        // in op-sqlite (`cannot start a transaction within a transaction`).
        // Instead we hand-roll the bridge: query the synced VIEWs directly
        // via PowerSync's getAll() (no surrounding transaction) and push
        // rows into the TinyBase store. Auto-write back (TinyBase → SQLite)
        // is deferred to a later iteration.
        void tabularConfig;
        const syncedTables = SYNCED_TABLE_IDS;
        // snake_case (SQL/PowerSync) ↔ camelCase (TinyBase schema)
        const snakeToCamel = (s: string) => s.replace(/_([a-z])/g, (_, c) => c.toUpperCase());
        const camelToSnake = (s: string) => s.replace(/([A-Z])/g, '_$1').toLowerCase();
        // SQLite stores booleans as integers (0/1). TinyBase's typed schema
        // rejects integer cells declared as boolean and silently drops them,
        // so checkboxes stay stuck at false even after a successful pull.
        // Build a (tinybaseTableId → Set<booleanCellId>) index from the
        // schema and coerce 0/1 → false/true on the way in.
        const booleanCells: Record<string, Set<string>> = {};
        for (const [tableId, tableSchema] of Object.entries(TABLES_SCHEMA as Record<string, Record<string, any>>)) {
          const set = new Set<string>();
          for (const [cellId, def] of Object.entries(tableSchema)) {
            if ((def as any)?.type === 'boolean') set.add(cellId);
          }
          if (set.size) booleanCells[tableId] = set;
        }
        let applyingPull = false;
        // Row IDs (per camelCase TinyBase table) that have an in-flight push.
        // The pull keeps the TinyBase-local value for these rows instead of
        // overwriting them with the SQLite-side value — otherwise a pull that
        // races a push would snap the just-tapped checkbox back to its stale
        // server state. Entries are added when a push enqueues and removed
        // when the push's SQL execute resolves (at which point SQLite has the
        // user's value and is safe to read).
        const pendingPushes = new Map<string, Set<string>>();
        const markPending = (tableId: string, rowId: string) => {
          let set = pendingPushes.get(tableId);
          if (!set) {
            set = new Set();
            pendingPushes.set(tableId, set);
          }
          set.add(rowId);
        };
        const clearPending = (tableId: string, rowId: string) => {
          const set = pendingPushes.get(tableId);
          if (!set) return;
          set.delete(rowId);
          if (set.size === 0) pendingPushes.delete(tableId);
        };
        const pullFromSQLite = async () => {
          try {
            const tablesData: Record<string, Record<string, Record<string, any>>> = {};
            for (const t of syncedTables) {
              const rows = await powersync.getAll<Record<string, any>>(`SELECT * FROM "${t}"`);
              const next: Record<string, Record<string, any>> = {};
              const tinyTableId = snakeToCamel(t);
              const boolSet = booleanCells[tinyTableId];
              for (const r of rows) {
                const id = (r as any).id;
                if (!id) continue;
                // SQLite columns are snake_case (PowerSync convention); the
                // TinyBase schema uses camelCase keys. Translate on the way in
                // so setTable doesn't drop the cells. Skip nulls — TinyBase
                // can't store null, and the column default will apply instead.
                const rest: Record<string, any> = {};
                for (const k of Object.keys(r)) {
                  if (k === 'id') continue;
                  const v = (r as any)[k];
                  if (v === null || v === undefined) continue;
                  const camelK = snakeToCamel(k);
                  rest[camelK] = boolSet?.has(camelK) ? v === 1 || v === true || v === '1' : v;
                }
                next[String(id)] = rest;
              }
              // TinyBase table id is camelCase too (protocolTasks, not
              // protocol_tasks); use it as the destination key.
              tablesData[tinyTableId] = next;
            }
            // Overlay pending-push rows with their current TinyBase value so
            // the upcoming setTable preserves the user's optimistic UI for
            // any tap that hasn't fully round-tripped yet.
            for (const [tableId, pending] of pendingPushes) {
              if (!pending.size) continue;
              const target = tablesData[tableId] ?? (tablesData[tableId] = {});
              const currentTable = store.getTable(tableId);
              for (const rowId of pending) {
                const currentRow = currentTable[rowId];
                if (currentRow && Object.keys(currentRow).length) {
                  target[rowId] = { ...currentRow };
                } else {
                  // User deleted the row locally; honor that by omitting it.
                  delete target[rowId];
                }
              }
            }
            // Single TinyBase transaction so subscribers see one update.
            // applyingPull guards only the synchronous commit so the push
            // listener can tell echo writes from real user mutations. It must
            // NOT span the whole async fetch — otherwise taps that land while
            // a pull is in flight get silently dropped and the next setTable
            // snaps the UI back to the SQLite-old state.
            applyingPull = true;
            try {
              store.transaction(() => {
                for (const [tableId, rows] of Object.entries(tablesData)) {
                  store.setTable(tableId, rows);
                }
                const uid = (store.getValue('currentUserId') as string) || '';
                if (uid) {
                  const horses = tablesData['horses'] ?? {};
                  // Keys are now camelCase after the snake→camel conversion.
                  const ownerHorse = Object.entries(horses).find(([, h]) => h.ownerId === uid && h.status === 'active');
                  if (ownerHorse) store.setValue('currentHorseId', ownerHorse[0]);
                }
              });
            } finally {
              applyingPull = false;
            }
          } catch (e) {
            console.warn('[provider] pullFromSQLite failed', String(e));
          }
        };
        // Single async queue for ALL SQL touching the synced views — pulls
        // and pushes both append to it. This prevents the canonical race:
        // user taps a checkbox → push queues an UPDATE → before it lands,
        // a pull SELECT * runs and reads the still-old row → setTable wipes
        // the optimistic UI back to "unchecked". Serializing means pulls
        // always see a SQLite state that contains every push that was issued
        // before them.
        let opChain: Promise<unknown> = Promise.resolve();
        const enqueue = <T,>(label: string, fn: () => Promise<T>): Promise<T> => {
          const next = opChain.then(fn, fn).catch((e) => {
            console.warn(`[sync] ${label} failed`, String(e));
            return undefined as unknown as T;
          });
          opChain = next;
          return next as Promise<T>;
        };
        let pullPending = false;
        const schedulePull = () => {
          // Coalesce: if a pull is already queued, don't add another. When
          // the queued pull starts running it flips the flag back so a fresh
          // pull can be queued for any post-pull changes.
          if (pullPending) return;
          pullPending = true;
          enqueue('pull', async () => {
            pullPending = false;
            await pullFromSQLite();
          });
        };
        powersync.registerListener({
          statusChanged: (s) => {
            if (!s.dataFlowStatus?.downloading && s.hasSynced) schedulePull();
          },
        });
        schedulePull();

        // TinyBase → PowerSync push. When a screen mutates the TinyBase store,
        // diff the transaction and translate to INSERT/UPDATE/DELETE on the
        // synced VIEW. PowerSync's auto-generated INSTEAD-OF triggers handle
        // the rest: write to ps_data__<table> and enqueue powersync_crud,
        // which BackendConnector.uploadData() then POSTs to Laravel.
        // TinyBase table IDs are camelCase; PowerSync views are snake_case.
        // syncedSet/viewColumns are keyed by the snake_case SQL view name.
        const syncedSet = new Set<string>(syncedTables);
        const viewColumns: Record<string, Set<string>> = {};
        for (const t of syncedTables) {
          const cols = await powersync.getAll<{ name: string }>(`PRAGMA table_info("${t}")`);
          viewColumns[t] = new Set(cols.map((c) => c.name));
        }
        store.addDidFinishTransactionListener(() => {
          // Skip our own pull-driven setTable calls. The flag is synchronous
          // (set only during the pull's `store.transaction(...)` commit) so
          // user mutations that land during a pull's async fetch still push.
          if (applyingPull) return;
          const log = store.getTransactionLog();
          // log = [cellsTouched, valuesTouched, changedCells, invalidCells,
          //        changedValues, invalidValues, changedTableIds,
          //        changedRowIds, changedCellIds, changedValueIds]
          const changedCells = log[2] as any;
          const changedRowIds = log[7] as any;
          if (!changedCells && !changedRowIds) return;

          // Snapshot SQL ops outside any await so we don't fight TinyBase's
          // transaction semantics — execute serially after. Each op also
          // carries its (camelCase tableId, rowId) so the pull bridge can
          // skip those rows while the push is in flight.
          const ops: Array<{ sql: string; args: any[]; tableId: string; rowId: string }> = [];

          for (const tableId of Object.keys(changedRowIds ?? {})) {
            const viewName = camelToSnake(tableId);
            if (!syncedSet.has(viewName)) continue;
            const allowedCols = viewColumns[viewName];
            for (const [rowId, addedOrRemoved] of Object.entries(changedRowIds[tableId] as Record<string, number>)) {
              if (addedOrRemoved === -1) {
                ops.push({ sql: `DELETE FROM "${viewName}" WHERE id = ?`, args: [rowId], tableId, rowId });
              } else if (addedOrRemoved === 1) {
                // New row — INSERT with every non-null cell that the view exposes.
                const row = store.getRow(tableId, rowId);
                const cols: string[] = ['id'];
                const placeholders: string[] = ['?'];
                const vals: any[] = [rowId];
                for (const [k, v] of Object.entries(row)) {
                  if (v === undefined || v === null) continue;
                  const sqlCol = camelToSnake(k);
                  if (allowedCols && !allowedCols.has(sqlCol)) continue;
                  cols.push(`"${sqlCol}"`);
                  placeholders.push('?');
                  vals.push(typeof v === 'boolean' ? (v ? 1 : 0) : v);
                }
                ops.push({
                  sql: `INSERT INTO "${viewName}" (${cols.join(',')}) VALUES (${placeholders.join(',')})`,
                  args: vals,
                  tableId,
                  rowId,
                });
              }
            }
          }

          // Existing rows whose cells changed (skip the ones we already added/removed above)
          for (const tableId of Object.keys(changedCells ?? {})) {
            const viewName = camelToSnake(tableId);
            if (!syncedSet.has(viewName)) continue;
            const allowedCols = viewColumns[viewName];
            const rowIdsAdded = changedRowIds?.[tableId] ?? {};
            for (const [rowId, cellChanges] of Object.entries(changedCells[tableId] as Record<string, Record<string, [any, any]>>)) {
              if (rowIdsAdded[rowId] !== undefined) continue; // already INSERT/DELETE'd
              const sets: string[] = [];
              const vals: any[] = [];
              for (const [cellId, change] of Object.entries(cellChanges)) {
                const sqlCol = camelToSnake(cellId);
                if (allowedCols && !allowedCols.has(sqlCol)) continue;
                const newValue = change[1];
                sets.push(`"${sqlCol}" = ?`);
                // SQLite has no boolean type; op-sqlite passes JS booleans
                // through as their numeric equivalents inconsistently, so
                // coerce to 0/1 here to keep the column's INTEGER affinity.
                vals.push(
                  typeof newValue === 'boolean'
                    ? (newValue ? 1 : 0)
                    : (newValue ?? null),
                );
              }
              if (!sets.length) continue;
              vals.push(rowId);
              ops.push({
                sql: `UPDATE "${viewName}" SET ${sets.join(', ')} WHERE id = ?`,
                args: vals,
                tableId,
                rowId,
              });
            }
          }

          if (!ops.length) return;
          // Mark rows as pending BEFORE enqueueing so any pull that races
          // sees the user's TinyBase value, not the still-old SQLite value.
          // Clear once the SQL execute resolves — at that point SQLite has
          // the user's change and any subsequent pull will read it correctly.
          for (const op of ops) {
            markPending(op.tableId, op.rowId);
            enqueue('push', async () => {
              try {
                await powersync.execute(op.sql, op.args);
              } finally {
                clearPending(op.tableId, op.rowId);
              }
            });
          }
        });

        console.log('[provider] direct bridge wired (pull + push)');

        const relationships = createRelationships(store);
        setupRelationships(relationships);

        // Auto-reconnect if we already have stored credentials.
        const creds = await loadCredentials();
        console.log('[provider] stored creds?', !!creds);
        if (creds) {
          setIsLoggedIn(true);
          setCurrentUserId(creds.userId);
          store.setValue('currentUserId', creds.userId);
          connectToBackend(powersync);
        }

        if (!cancelled) setBundle({ store, powersync, relationships });
      } catch (err) {
        console.error('[provider] boot failed', err);
      }
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const connectToBackend = useCallback(
    async (powersync: AbstractPowerSyncDatabase) => {
      if (connectedRef.current) return;
      connectedRef.current = true;
      setSyncStatus('connecting');
      console.log('[provider] calling powersync.connect(...)');
      try {
        await powersync.connect(new LaravelConnector());
        console.log('[provider] connect resolved — syncStatus=connected');
        setSyncStatus('connected');
      } catch (err) {
        console.error('[provider] connect failed', err);
        setSyncStatus('error');
        connectedRef.current = false;
      }
    },
    [],
  );

  const login = useCallback(
    async (email: string, password: string) => {
      if (!bundle) throw new Error('Database not ready');
      const res: LoginResponse = await loginRequest(email, password);
      await saveCredentials({
        email,
        password,
        userId: String(res.user.id),
        endpoint: res.endpoint,
      });
      setIsLoggedIn(true);
      setCurrentUserId(String(res.user.id));
      // Stamp the currentUserId into the TinyBase Values so existing hooks
      // that read `useCurrentUserId()` keep working.
      bundle.store.setValue('currentUserId', String(res.user.id));
      await connectToBackend(bundle.powersync);
    },
    [bundle, connectToBackend],
  );

  const logout = useCallback(async () => {
    if (!bundle) return;
    setSyncStatus('idle');
    connectedRef.current = false;
    try {
      await bundle.powersync.disconnectAndClear();
    } catch (err) {
      if (__DEV__) console.warn('[provider] disconnect failed', err);
    }
    await clearCredentials();
    setIsLoggedIn(false);
    setCurrentUserId(null);
    bundle.store.setValue('currentUserId', '');
  }, [bundle]);

  const value = useMemo<DbContextValue | null>(() => {
    if (!bundle) return null;
    return {
      store: bundle.store,
      powersync: bundle.powersync,
      isLoggedIn,
      isConnected: syncStatus === 'connected',
      syncStatus,
      currentUserId,
      login,
      logout,
    };
  }, [bundle, isLoggedIn, syncStatus, currentUserId, login, logout]);

  if (!bundle || !value) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#FBF8F3' }}>
        <ActivityIndicator color="#18BAB0" />
      </View>
    );
  }

  return (
    <DbContext.Provider value={value}>
      <Provider store={bundle.store} relationships={bundle.relationships}>
        {children}
      </Provider>
    </DbContext.Provider>
  );
}

export function useDb(): DbContextValue {
  const ctx = useContext(DbContext);
  if (!ctx) throw new Error('useDb must be used inside <DbProvider>');
  return ctx;
}
