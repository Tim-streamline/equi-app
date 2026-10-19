export type RefreshProtection = {
  reason?: () => string | null;
  prepare?: () => Promise<void>;
};

/** Reload only after local writes commit; server uploads may remain queued. */
export class RefreshSafety {
  private protections = new Set<RefreshProtection>();
  private listeners = new Set<() => void>();
  private revision = 0;

  subscribe = (listener: () => void) => {
    this.listeners.add(listener);
    return () => { this.listeners.delete(listener); };
  };
  changed = () => { this.listeners.forEach(listener => listener()); };
  register = (protection: RefreshProtection) => {
    this.protections.add(protection);
    this.revision++;
    this.changed();
    return () => { this.protections.delete(protection); this.revision++; this.changed(); };
  };
  reason = () => {
    for (const protection of this.protections) {
      const reason = protection.reason?.();
      if (reason) return reason;
    }
    return null;
  };
  async prepare() {
    const blocked = this.reason();
    if (blocked) throw new Error(blocked);
    const revision = this.revision;
    for (const protection of [...this.protections]) {
      if (revision !== this.revision) throw new Error('De geopende intake is gewijzigd. Probeer opnieuw.');
      await protection.prepare?.();
    }
    if (revision !== this.revision) throw new Error('De geopende intake is gewijzigd. Probeer opnieuw.');
    const reason = this.reason();
    if (reason) throw new Error(reason);
  }
}
