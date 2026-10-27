export type LibraryAccess = { hasPlus: boolean; hasEquiBoost?: boolean; equiBoostExpiresAt?: string | null; hasBasic?: boolean; unlockedIds: string[]; allowedIds?: string[]; credits?: number };
export type LibraryCardItem = {
  id: string; title?: string; description?: string; format?: string;
  heroImageUrl?: string | null; durationLabel?: string | null; authorName?: string | null; creditCost?: number; isPlus?: boolean; equiboostAccess?: 'only' | 'included' | null;
};

export function libraryCanRead(item: LibraryCardItem, access?: LibraryAccess | null): boolean {
  if (access?.allowedIds) return access.allowedIds.includes(item.id);
  if (access?.unlockedIds.includes(item.id)) return true;
  if (item.equiboostAccess) return !!access?.hasEquiBoost && (!access.equiBoostExpiresAt || Date.parse(access.equiBoostExpiresAt) > Date.now());
  return item.isPlus ? !!access?.hasPlus : (Number(item.creditCost) || 0) === 0;
}

export function libraryFormat(format?: string) {
  return ({ video: 'Video', article: 'Artikel', audio: 'Audio', podcast: 'Podcast', course: 'Cursus', program: 'Programma' } as Record<string, string>)[format ?? 'article'] ?? 'Artikel';
}

export function libraryAccessLabel(item: LibraryCardItem, access?: LibraryAccess | null): { label: string; locked: boolean } {
  if (access?.unlockedIds.includes(item.id)) return { label: 'Ontgrendeld', locked: false };
  if (item.equiboostAccess) {
    const allowed = libraryCanRead(item, access);
    const cost = Number(item.creditCost) || 0;
    return { label: allowed ? 'Inbegrepen bij EquiBoost' : item.equiboostAccess === 'only' ? 'Plus only · EquiBoost' : `${cost} ${cost === 1 ? 'credit' : 'credits'} · gratis tijdens EquiBoost`, locked: !allowed };
  }
  if (item.isPlus) return { label: 'Gratis', locked: !(access?.allowedIds ? access.allowedIds.includes(item.id) : access?.hasPlus) };
  const credits = Number(item.creditCost) || 0;
  return credits > 0 ? { label: `${credits} ${credits === 1 ? 'credit' : 'credits'}`, locked: true } : { label: 'Gratis', locked: false };
}

export function libraryPath(item: LibraryCardItem) {
  return { pathname: item.format === 'video' ? '/(tabs)/library/video/[id]' : '/(tabs)/library/article/[id]', params: { id: item.id } };
}

/** Only include meaningful metadata; article duration is not a reading-time field. */
export function libraryMetadata(item: LibraryCardItem): string {
  const parts = [libraryFormat(item.format)];
  const duration = item.durationLabel?.trim();
  const validDuration = duration && (
    (/^\d+(?:[.,]\d+)?(?:\s*(?:min(?:uten)?|m|sec(?:onden)?|s|uur|h))?$/i.test(duration) && parseFloat(duration.replace(',', '.')) > 0)
    || (/^\d+(?::[0-5]\d){1,2}$/.test(duration) && duration.split(':').some(part => Number(part) > 0))
  );
  if (['video', 'audio', 'podcast'].includes(item.format ?? '') && validDuration) parts.push(duration);
  const author = item.authorName?.trim();
  if (author && !/^(undefined|null)$/i.test(author)) parts.push(`door ${author}`);
  return parts.join(' · ');
}
