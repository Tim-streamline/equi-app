import { useQuery } from '@powersync/react';
import { useDb } from '@/db/provider';
import { useLibraryAccessRecords } from './useLibraryAccessRecords';
import { useLibraryResource } from './useLibraryResource';
import type { LibraryAccess, LibraryCardItem } from '@/lib/library';
import type { LibraryAttachment } from '@/components/library/LibraryAttachments';

type Chapter = { id: string; title: string; startLabel: string };
export type LibraryContentData = {
  item: LibraryCardItem; access: LibraryAccess; canRead: boolean; body: string | null;
  chapters: Chapter[]; attachments: LibraryAttachment[];
};
type Row = {
  id: string; title: string; description: string; format: string; heroImageUrl: string;
  authorName: string; durationLabel: string; creditCost: number; isPlus: number;
  content_id: string | null; body: string | null; chapters: string | null; attachments: string | null;
};
export function useLibraryContent(itemId: string) {
  const { isLoggedIn, currentUserId, isConnected } = useDb();
  const { grants, isLoading: grantsLoading } = useLibraryAccessRecords();
  const grant = grants.find(row => row.item_id === itemId);
  const { data: rows } = useQuery<Row>(`
    SELECT i.id, i.title, i.description, i.format, i.hero_image_url AS heroImageUrl,
           i.duration_label AS durationLabel, i.credit_cost AS creditCost, i.is_plus AS isPlus,
           t.name AS authorName, c.id AS content_id, c.body, c.chapters, c.attachments
    FROM library_items i
    LEFT JOIN therapists t ON t.id = i.author_therapist_id
    LEFT JOIN library_contents c ON c.id = i.id
      AND EXISTS (SELECT 1 FROM library_item_access a WHERE a.item_id = i.id AND a.user_id = ?)
    WHERE i.id = ? AND i.published_at IS NOT NULL AND datetime(i.published_at) <= datetime('now')`,
  [isLoggedIn ? currentUserId : null, itemId]);
  // Reading a granted item never depends on HTTP. Live account data is only
  // needed to offer a purchase on a locked item.
  const account = useLibraryResource<LibraryAccess>('/access', !grantsLoading && !grant);
  const row = rows.find(row => row.id === itemId);
  const canRead = !!(isLoggedIn && grant && row?.content_id);
  const localAccess: LibraryAccess = {
    hasPlus: grant?.reason === 'plus', unlockedIds: grants.filter(g => g.reason === 'unlocked').map(g => g.item_id),
    allowedIds: grants.map(g => g.item_id),
  };
  const data: LibraryContentData | null = row && (grant || account.data) ? {
    item: { id: row.id, title: row.title, description: row.description, format: row.format,
      heroImageUrl: row.heroImageUrl, durationLabel: row.durationLabel, authorName: row.authorName,
      creditCost: row.creditCost, isPlus: !!row.isPlus },
    access: account.data ?? localAccess, canRead,
    body: canRead ? row.body : null,
    chapters: canRead ? JSON.parse(row.chapters ?? '[]') : [],
    attachments: canRead ? JSON.parse(row.attachments ?? '[]') : [],
  } : null;
  return {
    data, error: grant ? null : account.error, refresh: account.refresh,
    update: (result: LibraryContentData) => account.update(result.access),
    pendingContent: !canRead && !!row && (!!grant || (!!account.data && (
      account.data.unlockedIds.includes(itemId) || (row.isPlus ? account.data.hasPlus : row.creditCost === 0)
    ))),
    isConnected,
  };
}
