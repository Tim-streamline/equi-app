import { PowerSyncDatabase as BasePowerSyncDatabase } from '@powersync/web/umd';

type StatusPrototype = { serializeError?: (error: unknown) => unknown };
const tolerant = new WeakSet<object>();
/**
 * SDK 1.53.1 reads `.name` from every upload or download error that is not `undefined`.
 * A rejection without a value (`null`) therefore threw inside the status listener, which
 * ended the upload loop and made "Upload opnieuw proberen" fail. Patch the class the
 * database itself uses: importing it separately can resolve to another copy of the SDK.
 */
function tolerateMissingSyncErrors(status: object | null | undefined) {
  const prototype: StatusPrototype | null = status ? Object.getPrototypeOf(status) : null;
  const original = prototype?.serializeError;
  if (!prototype || typeof original !== 'function' || tolerant.has(prototype)) return;
  tolerant.add(prototype);
  prototype.serializeError = function (this: unknown, error: unknown) {
    return error == null ? undefined : original.call(this, error);
  };
}

export class PowerSyncDatabase extends BasePowerSyncDatabase {
  constructor(...args: ConstructorParameters<typeof BasePowerSyncDatabase>) {
    super(...args);
    tolerateMissingSyncErrors(this.currentStatus);
  }
  async connect(...args: Parameters<BasePowerSyncDatabase['connect']>) {
    await super.connect(...args);
    // SDK 1.53.1 can lose subscription updates during connection startup.
    // Reapply the current set after startup, when the stream can receive updates.
    // https://github.com/powersync-ja/powersync-js/pull/1078
    this.syncStreamImplementation?.updateSubscriptions(this.connectionManager.activeStreams);
  }
}
