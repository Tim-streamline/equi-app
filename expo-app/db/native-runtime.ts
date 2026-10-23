import { PowerSyncDatabase, type AbstractPowerSyncDatabase } from '@powersync/react-native';
import { OPSqliteOpenFactory } from '@powersync/op-sqlite';
import { AppSchema } from './powersync-schema';
import { LaravelConnector } from './connector';

// Navigation can remount DbProvider after login. The SQLite connection and sync
// client belong to the app process: opening a second client for the same file
// leaves the old stream active and prevents the new client from uploading.
let database: Promise<AbstractPowerSyncDatabase> | undefined;
let connecting: Promise<void> | undefined;

export function getNativeDatabase(): Promise<AbstractPowerSyncDatabase> {
  if (!database) {
    const ps = new PowerSyncDatabase({
      schema: AppSchema,
      database: new OPSqliteOpenFactory({ dbFilename: 'equinova.db' }),
      logger: console as any,
    });
    database = ps.init().then(() => ps).catch(async error => {
      database = undefined;
      await ps.close();
      throw error;
    });
  }
  return database;
}

export async function connectNativeDatabase(ps: AbstractPowerSyncDatabase): Promise<void> {
  if (connecting) return connecting;
  // A remount only needs to observe the existing stream, including its retries.
  if (ps.currentStatus.connected || ps.currentStatus.connecting) return;
  connecting = ps.connect(new LaravelConnector());
  try {
    await connecting;
  } finally {
    connecting = undefined;
  }
}
