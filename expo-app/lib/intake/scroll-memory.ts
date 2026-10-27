/** Where the owner was on an intake page. Kept in memory only, never stored on the device. */
const positions = new Map<string, { y: number; at: number }>();

/** Only a recent position is restored, so opening a page later still starts at the top. */
export const SCROLL_MEMORY_MS = 2 * 60 * 1000;

export function rememberScroll(key: string, y: number, now = Date.now()) {
  positions.set(key, { y: Math.max(0, y), at: now });
}

export function rememberedScroll(key: string, now = Date.now()): number | null {
  const saved = positions.get(key);
  if (!saved || now - saved.at > SCROLL_MEMORY_MS || saved.y < 1) return null;
  return saved.y;
}

export function forgetScroll(key: string) {
  positions.delete(key);
}
