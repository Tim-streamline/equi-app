/**
 * Saves order-list ticks one request at a time, in the order they were tapped. Requests that run side by side
 * can be handled by the server in any order, which would store "ordered" for a product that shows as unticked.
 * While requests are pending the list shows the customer's own taps; once the last one is answered it shows
 * what the server confirmed, which also undoes a tick that could not be saved.
 */
export type SendOrderTick = (keys: string[], ordered: boolean) => Promise<string[]>;

/**
 * Local ticks shown over the dashboard's `orderedKeys`, for one horse and protocol. `settledAt` is null while
 * requests are pending; after the last answer (or failure) it is the dashboard request number at that moment.
 */
export type OrderOverlay = { horseId: string; protocolId: string; keys: string[]; settledAt: number | null };

/**
 * What the order list shows. The overlay wins while saving, and afterwards until a dashboard response arrives whose
 * request started after the last write was answered: only that one can include it (and another device's later
 * change, such as undoing this tick). A poll that was already under way, a cached response, or another horse's
 * or protocol's data never decides. Request numbers, unlike the second-precision `generatedAt`, cannot collide.
 */
export function shownOrderedKeys(overlay: OrderOverlay | null, horseId: string, protocolId: string | undefined,
  serverKeys: string[], dashboardRevision: number): string[] {
  if (!overlay || !protocolId || overlay.horseId !== horseId || overlay.protocolId !== protocolId) return serverKeys;
  return overlay.settledAt === null || dashboardRevision <= overlay.settledAt ? overlay.keys : serverKeys;
}

export function createOrderTicks(send: SendOrderTick) {
  let pending = 0;
  let local: string[] = [];
  let confirmed: string[] = [];
  let failed = false;
  let chain: Promise<void> = Promise.resolve();

  return {
    /** `current` is what the list shows now; it is only read when nothing is pending. */
    /** `settled` is true for the update after the last pending request was answered or failed. */
    toggle(current: string[], keys: string[], ordered: boolean, onUpdate: (keys: string[], failed: boolean, settled: boolean) => void) {
      if (pending === 0) { local = current; confirmed = current; failed = false; }
      local = ordered ? Array.from(new Set([...local, ...keys])) : local.filter((key) => !keys.includes(key));
      pending += 1;
      onUpdate(local, false, false);
      chain = chain.then(async () => {
        try { confirmed = await send(keys, ordered); } catch { failed = true; }
        pending -= 1;
        if (pending === 0) onUpdate(confirmed, failed, true);
      });
    },
  };
}
