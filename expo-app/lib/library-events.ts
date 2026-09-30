const listeners = new Set<(itemId: string) => void>();
export function onLibraryUnlock(listener: (itemId: string) => void) {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}
export function notifyLibraryUnlock(itemId: string) {
  for (const listener of listeners) listener(itemId);
}
