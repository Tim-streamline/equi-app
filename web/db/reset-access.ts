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
