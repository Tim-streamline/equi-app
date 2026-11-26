/**
 * Saves a plan's order-list ticks or order setting one request at a time, in the order they were tapped. Requests
 * that run side by side can be handled by the server in any order, which would store "ordered" for a product that
 * shows as unticked. While requests are pending the list shows the customer's own taps; once the last one is
 * answered it shows what the server confirmed, which also undoes a change that could not be saved.
 */

/**
 * A local value shown over the dashboard's, for one plan. `settledAt` is null while requests are pending; after the
 * last answer (or failure) it is the dashboard request number at that moment.
 */
export type Overlay<T> = { value: T; settledAt: number | null };

/**
 * What the screen shows. The overlay wins while saving, and afterwards until a dashboard response arrives whose
 * request started after the last write was answered: only that one can include it (and another device's later
 * change, such as undoing this tick). A poll that was already under way or a cached response never decides.
 * Request numbers, unlike the second-precision `generatedAt`, cannot collide.
 */
export function shownValue<T>(overlay: Overlay<T> | null | undefined, serverValue: T, dashboardRevision: number): T {
  return overlay && (overlay.settledAt === null || dashboardRevision <= overlay.settledAt) ? overlay.value : serverValue;
}

/** Ticks or unticks `keys` in a list of ordered keys. */
export const toggleKeys = (keys: string[], ordered: boolean) => (shown: string[]) =>
  ordered ? Array.from(new Set([...shown, ...keys])) : shown.filter((key) => !keys.includes(key));

/**
 * One plan's queue of saves for one value. With `replaces`, each save stores the whole value (a setting), so only
 * the last request's failure counts; otherwise (ticks) any failure in a burst means a tap was lost.
 */
export function createSerialSave<T>({ replaces = false } = {}) {
  let pending = 0;
  let local: T;
  let confirmed: T;
  let failed = false;
  let chain: Promise<void> = Promise.resolve();

  return {
    /**
     * `current` is what the screen shows now; it is only read when nothing is pending. `change` applies the tap to
     * the shown value and `send` saves it, resolving to what the server stored. `settled` is true for the update
     * after the last pending request was answered or failed.
     */
    save(current: T, change: (shown: T) => T, send: () => Promise<T>, onUpdate: (value: T, failed: boolean, settled: boolean) => void) {
      if (pending === 0) { local = current; confirmed = current; failed = false; }
      local = change(local);
      pending += 1;
      onUpdate(local, false, false);
      chain = chain.then(async () => {
        try {
          confirmed = await send();
          if (replaces) failed = false;
        } catch {
          failed = true;
        }
        pending -= 1;
        if (pending === 0) onUpdate(confirmed, failed, true);
      });
    },
  };
}

export type SerialSave<T> = ReturnType<typeof createSerialSave<T>>;
