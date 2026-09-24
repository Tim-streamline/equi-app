// WA-SQLite's IndexedDB VFS limits paths to 64 bytes, including journal suffixes.
export async function databaseName(tabId: string, userId: string | null) {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify([tabId, userId])));
  const hash = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('').slice(0, 40);
  return `eq-${hash}.db`;
}
