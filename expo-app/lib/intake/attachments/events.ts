type AttachmentChange = { userId: string; id: string };
const listeners = new Set<(change: AttachmentChange) => void>();
export function onAttachmentsChanged(listener: (change: AttachmentChange) => void) {
  listeners.add(listener);
  return () => { listeners.delete(listener); };
}
export function attachmentsChanged(change: AttachmentChange) { listeners.forEach(listener => listener(change)); }
