export type LibraryAccess = { hasPlus: boolean; unlockedIds: string[]; credits?: number };
export type LibraryCardItem = {
  id: string; title?: string; description?: string; format?: string;
  heroImageUrl?: string | null; durationLabel?: string; creditCost?: number; isPlus?: boolean;
};

export function libraryFormat(format?: string) {
  return ({ video: 'Video', article: 'Artikel', audio: 'Audio', podcast: 'Podcast', course: 'Cursus', program: 'Programma' } as Record<string, string>)[format ?? 'article'] ?? 'Artikel';
}

export function libraryAccessLabel(item: LibraryCardItem, access?: LibraryAccess | null): { label: string; locked: boolean } {
  if (access?.hasPlus) return { label: '', locked: false };
  if (access?.unlockedIds.includes(item.id)) return { label: 'Al ontgrendeld', locked: false };
  if (item.isPlus) return { label: 'Plus', locked: true };
  const credits = Number(item.creditCost) || 0;
  return credits > 0 ? { label: `${credits} ${credits === 1 ? 'credit' : 'credits'}`, locked: true } : { label: 'Gratis', locked: false };
}

export function libraryPath(item: LibraryCardItem) {
  return { pathname: item.format === 'video' ? '/(tabs)/library/video/[id]' : '/(tabs)/library/article/[id]', params: { id: item.id } };
}
