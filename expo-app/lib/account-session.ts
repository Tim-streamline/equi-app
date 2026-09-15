/** Serialize credential transitions with token writes tied to one account session. */
export class AccountSessionQueue {
  private tail: Promise<unknown> = Promise.resolve();
  private currentRevision = 0;

  get revision() { return this.currentRevision; }

  private enqueue<T>(action: () => Promise<T>): Promise<T> {
    const result = this.tail.then(action);
    this.tail = result.catch(() => undefined);
    return result;
  }

  transition<T>(action: () => Promise<T>): Promise<T> {
    // Invalidate pending permission/token work immediately, before waiting for an
    // already submitted request. Cleanup then becomes the final old-account write.
    this.currentRevision++;
    return this.enqueue(action);
  }

  forSession<T>(revision: number, action: () => Promise<T>): Promise<T | undefined> {
    return this.enqueue(() => this.currentRevision === revision ? action() : Promise.resolve(undefined));
  }
}

export const accountSession = new AccountSessionQueue();
