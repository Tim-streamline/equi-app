export type CommunityCategory = { id: string; slug: string; label: string };
export type CommunityTag = { id: string; label: string };
export type CommunityMedia = { id: string; type: 'image' | 'video'; path: string; mimeType: string };
export type CommunityContent = {
  id: string; body: string; authorUserId: string | null; authorTherapistId: string | null;
  authorName: string; authorInitial: string; authorIsExpert: boolean; createdAt: string;
  editedAt: string | null; owned: boolean; liked: boolean; likesCount: number;
};
export type CommunityReply = CommunityContent & {
  parentReplyId: string | null;
  parentReply: { id: string; authorName: string; body: string } | null;
};
export type CommunityPost = CommunityContent & {
  category: CommunityCategory | null; tags: CommunityTag[]; media: CommunityMedia[];
  bookmarked: boolean; repliesCount: number; expertReply: CommunityReply | null;
  locked: boolean; pinned: boolean;
};
export type CommunityPage<T> = { data: T[]; current_page: number; last_page: number; total: number };
export type CommunityFeedData = {
  posts: CommunityPage<CommunityPost>; categories: CommunityCategory[]; tags: CommunityTag[];
  canParticipate: boolean; currentUserId: string;
};
export type CommunityThreadData = {
  post: CommunityPost; replies: CommunityPage<CommunityReply>; canParticipate: boolean; currentUserId: string;
};
export type MutedAuthor = { id: string; type: 'user' | 'therapist'; name: string };

export function communityQuery(category: string, bookmarked: boolean, page: number) {
  const params = new URLSearchParams({ page: String(page) });
  if (category) params.set('category_id', category);
  if (bookmarked) params.set('bookmarked', '1');
  return `/api/community?${params}`;
}

export function contentActions(owned: boolean, canParticipate: boolean, locked: boolean) {
  return owned
    ? [...(canParticipate && !locked ? ['edit'] : []), 'delete', 'share']
    : ['report', 'mute', 'share'];
}

export function communityErrorMessage(status: number, message?: string) {
  if (status === 404) return 'Dit bericht is niet meer beschikbaar.';
  if (status === 401) return 'Meld je opnieuw aan om Community te gebruiken.';
  if (status === 429) return 'Je doet dit te snel. Probeer het over een minuut opnieuw.';
  if (status >= 500) return 'Community is tijdelijk niet bereikbaar. Probeer opnieuw.';
  return message || 'Deze actie is niet gelukt. Probeer opnieuw.';
}

export function relativeTime(date: string, now = Date.now()) {
  const minutes = Math.max(0, Math.floor((now - Date.parse(date)) / 60000));
  if (!Number.isFinite(minutes)) return '';
  if (minutes < 1) return 'zojuist';
  if (minutes < 60) return `${minutes} min geleden`;
  if (minutes < 1440) return `${Math.floor(minutes / 60)} u geleden`;
  if (minutes < 2880) return 'gisteren';
  return new Date(date).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' });
}
