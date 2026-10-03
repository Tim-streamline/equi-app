import { PowerSyncDatabase as BasePowerSyncDatabase } from '@powersync/web/umd';

export class PowerSyncDatabase extends BasePowerSyncDatabase {
  async connect(...args: Parameters<BasePowerSyncDatabase['connect']>) {
    await super.connect(...args);
    // SDK 1.53.1 can lose subscription updates during connection startup.
    // Reapply the current set after startup, when the stream can receive updates.
    // https://github.com/powersync-ja/powersync-js/pull/1078
    this.syncStreamImplementation?.updateSubscriptions(this.connectionManager.activeStreams);
  }
}
