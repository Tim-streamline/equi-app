const listeners = new Set<() => void>();
export function onAttachmentsChanged(listener: () => void) {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}
export function attachmentsChanged() { listeners.forEach(listener => listener()); }
