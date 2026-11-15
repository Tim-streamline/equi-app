/** Coordinate account cleanup with open tabs, including shared dashboard caches. */
export class SyncResetAccess {
  private release: (() => void) | undefined;
  private held: Promise<unknown> | undefined;

  async leave() {
    this.release?.();
    await this.held;
    this.release = undefined;
    this.held = undefined;
  }

  /**
   * Whether no open tab has this account's local data open. Every version that stores
   * photos per tab holds the shared lock below while signed in, also versions that did
   * not yet hold a lock on their own tab identity.
   */
  async idle(userId: string): Promise<boolean> {
    if (!navigator.locks) return false;
    return navigator.locks.request(`equinova:local-data:${userId}`, { ifAvailable: true }, async lock => !!lock);
  }

  async enter(userId: string | null) {
    await this.leave();
    if (!userId || !navigator.locks) return;
    await new Promise<void>((resolve, reject) => {
      this.held = navigator.locks.request(`equinova:local-data:${userId}`, { mode: 'shared' }, () =>
        new Promise<void>(release => { this.release = release; resolve(); }));
      void this.held.catch(reject);
    });
  }

  async exclusive(userId: string, work: () => Promise<void>) {
    if (!navigator.locks) throw new Error('Je browser ondersteunt veilig herstellen niet. Gebruik een recente browser.');
    await this.leave();
    try {
      await navigator.locks.request(`equinova:local-data:${userId}`, { ifAvailable: true }, async lock => {
        if (!lock) throw new Error('Sluit de andere tabbladen van deze app en probeer opnieuw.');
        await work();
      });
    } finally { await this.enter(userId); }
  }
}
