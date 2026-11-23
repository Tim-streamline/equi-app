/**
 * Saves order-list ticks one request at a time, in the order they were tapped. Requests that run side by side
 * can be handled by the server in any order, which would store "ordered" for a product that shows as unticked.
 * While requests are pending the list shows the customer's own taps; once the last one is answered it shows
 * what the server confirmed, which also undoes a tick that could not be saved.
 */
export type SendOrderTick = (keys: string[], ordered: boolean) => Promise<string[]>;

export function createOrderTicks(send: SendOrderTick) {
  let pending = 0;
  let local: string[] = [];
  let confirmed: string[] = [];
  let failed = false;
  let chain: Promise<void> = Promise.resolve();

  return {
    /** `current` is what the list shows now; it is only read when nothing is pending. */
    toggle(current: string[], keys: string[], ordered: boolean, onUpdate: (keys: string[], failed: boolean) => void) {
      if (pending === 0) { local = current; confirmed = current; failed = false; }
      local = ordered ? Array.from(new Set([...local, ...keys])) : local.filter((key) => !keys.includes(key));
      pending += 1;
      onUpdate(local, false);
      chain = chain.then(async () => {
        try { confirmed = await send(keys, ordered); } catch { failed = true; }
        pending -= 1;
        if (pending === 0) { local = confirmed; onUpdate(confirmed, failed); }
      });
    },
  };
}
