// Typed selectors over the TinyBase store. Every screen consumes these instead
// of touching the store directly, so the domain shape stays in one place.

import { useMemo } from 'react';
import {
  useCell,
  useLocalRowIds,
  useRow,
  useRowIds,
  useStore,
  useTable,
  useValue,
} from 'tinybase/ui-react';
import type { Row } from 'tinybase';

// RFC4122 v4 UUID — Postgres `uuid` columns reject anything else. Hermes in
// RN 0.74+ ships `crypto.randomUUID` natively; we fall back to a
// Math.random implementation otherwise (lower entropy, fine for dev).
const newId = (): string =>
  typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
      });

import { IDS } from './ids';

type Indexed = { id: string; [key: string]: any };

function rows(table: Record<string, Row>): Indexed[] {
  return Object.entries(table).map(([id, row]) => ({ id, ...(row as any) }));
}

function sorted(list: Indexed[]): Indexed[] {
  return [...list].sort((a, b) => ((a.order as number) ?? 0) - ((b.order as number) ?? 0));
}

// ---------------------------------------------------------------- identity
export const useCurrentUserId = () => (useValue('currentUserId') as string) || IDS.user;
export const useCurrentHorseId = () => (useValue('currentHorseId') as string) || IDS.horse;

export function useCurrentUser() {
  const id = useCurrentUserId();
  return { id, ...useRow('users', id) } as Indexed;
}

export function useTherapist(id: string = IDS.therapist) {
  return { id, ...useRow('therapists', id) } as Indexed;
}

// ---------------------------------------------------------------- horses
export function useHorse(id?: string) {
  const fallback = useCurrentHorseId();
  const horseId = id ?? fallback;
  return { id: horseId, ...useRow('horses', horseId) } as Indexed;
}

export function useHorsesByOwner(ownerId?: string): Indexed[] {
  const uid = useCurrentUserId();
  const all = useTable('horses');
  const list = rows(all);
  return list.filter((h) => (h as any).ownerId === (ownerId ?? uid));
}

export function useFocusForHorse(horseId?: string) {
  const fallback = useCurrentHorseId();
  const id = horseId ?? fallback;
  const joins = rows(useTable('horseFocus')).filter((r: any) => r.horseId === id);
  const topics = useTable('focusTopics');
  return joins.map((j: any) => ({
    id: j.id,
    horseId: j.horseId,
    focusTopicId: j.focusTopicId,
    extraLabel: j.extraLabel,
    topic: { id: j.focusTopicId, ...(topics[j.focusTopicId] || {}) } as Indexed,
  }));
}

export function useFocusTopics() {
  return sorted(rows(useTable('focusTopics')) as any);
}

export function useHorseShares(horseId?: string) {
  const fallback = useCurrentHorseId();
  const id = horseId ?? fallback;
  const users = useTable('users');
  const therapists = useTable('therapists');
  return rows(useTable('horseShares'))
    .filter((r: any) => r.horseId === id)
    .map((r: any) => {
      const user = r.granteeUserId ? users[r.granteeUserId] : null;
      const therapist = r.therapistId ? therapists[r.therapistId] : null;
      return { ...r, user, therapist };
    });
}

export function useHorseStats(horseId?: string) {
  const fallback = useCurrentHorseId();
  const id = horseId ?? fallback;
  return sorted(rows(useTable('horseStats')).filter((r: any) => r.horseId === id) as any);
}

export function useTimeline(horseId?: string) {
  const fallback = useCurrentHorseId();
  const id = horseId ?? fallback;
  return sorted(rows(useTable('timelineEvents')).filter((r: any) => r.horseId === id) as any);
}

// ---------------------------------------------------------------- protocol
export function useActiveProtocolForHorse(horseId?: string) {
  const fallback = useCurrentHorseId();
  const id = horseId ?? fallback;
  const protocols = rows(useTable('protocols')) as any[];
  return protocols.find((p) => p.horseId === id && p.status === 'active') ?? null;
}

export function useProtocolPhases(protocolId: string) {
  return sorted(rows(useTable('protocolPhases')).filter((r: any) => r.protocolId === protocolId) as any);
}

export function usePhaseItems(phaseId: string) {
  return sorted(rows(useTable('protocolPhaseItems')).filter((r: any) => r.phaseId === phaseId) as any);
}

export function useProtocolAnalysis(protocolId: string) {
  const analysis = (rows(useTable('protocolAnalyses')) as any[]).find((a) => a.protocolId === protocolId);
  const advice = sorted(
    rows(useTable('protocolAdvice')).filter((r: any) => r.analysisId === analysis?.id) as any,
  );
  return analysis ? { ...analysis, advice } : null;
}

export function useProtocolTasks(protocolId: string) {
  return sorted(rows(useTable('protocolTasks')).filter((r: any) => r.protocolId === protocolId) as any);
}

export function useTaskCompletionsForDate(date: string) {
  const list = rows(useTable('protocolTaskCompletions')) as any[];
  return list.filter((r) => r.date === date);
}

export function useTodayTasks(protocolId: string, date: string) {
  const tasks = useProtocolTasks(protocolId);
  const completions = useTaskCompletionsForDate(date);
  return tasks.map((t: any) => {
    const c = completions.find((x) => x.taskId === t.id);
    return { ...t, done: !!c?.done, completionId: c?.id, doneAt: c?.doneAt };
  });
}

// ---------------------------------------------------------------- scanner
export function useScanResults() {
  return rows(useTable('scanResults')) as any[];
}
export function useScanResult(id: string) {
  return { id, ...useRow('scanResults', id) } as Indexed;
}
export function useScanIngredients(scanId: string) {
  return sorted(rows(useTable('scanIngredients')).filter((r: any) => r.scanId === scanId) as any);
}

// ---------------------------------------------------------------- library
export function useLibraryItems() {
  return sorted(rows(useTable('libraryItems')) as any);
}
export function useLibraryFeatured() {
  return (rows(useTable('libraryItems')) as any[]).find((i) => i.isFeatured);
}
export function useLibraryItem(id: string) {
  return { id, ...useRow('libraryItems', id) } as Indexed;
}
export function useLibraryChapters(itemId: string) {
  return sorted(rows(useTable('libraryChapters')).filter((r: any) => r.itemId === itemId) as any);
}
export function useLibraryArticleSections(itemId: string) {
  return sorted(rows(useTable('libraryArticleSections')).filter((r: any) => r.itemId === itemId) as any);
}
export function useLibraryCategories() {
  return sorted(rows(useTable('libraryCategories')) as any);
}
export function useActiveSeasonalTip() {
  return (rows(useTable('seasonalTips')) as any[]).find((s) => s.active);
}

// ---------------------------------------------------------------- community
export function useCommunityPosts() {
  return sorted(rows(useTable('communityPosts')) as any);
}
export function useCommunityPost(id: string) {
  return { id, ...useRow('communityPosts', id) } as Indexed;
}
export function useCommunityReplies(postId: string) {
  return sorted(rows(useTable('communityReplies')).filter((r: any) => r.postId === postId) as any);
}
export function useCommunityCategories() {
  return sorted(rows(useTable('communityCategories')) as any);
}
export function useCommunityPostTags(postId: string) {
  const tags = useTable('communityTags');
  return sorted(rows(useTable('communityPostTags')).filter((r: any) => r.postId === postId) as any).map(
    (r: any) => ({ ...r, label: (tags[r.tagId] as any)?.label ?? r.tagId }),
  );
}

// ---------------------------------------------------------------- subscription
export function useActiveSubscription() {
  const uid = useCurrentUserId();
  return (rows(useTable('subscriptions')) as any[]).find((s) => s.userId === uid && s.status === 'active');
}
export function usePlanBenefits(planId: string) {
  return sorted(rows(useTable('planBenefits')).filter((r: any) => r.planId === planId) as any);
}
export function usePayments(subscriptionId: string) {
  return sorted(rows(useTable('payments')).filter((r: any) => r.subscriptionId === subscriptionId) as any);
}
export function usePlan(id: string) {
  return { id, ...useRow('plans', id) } as Indexed;
}

// ---------------------------------------------------------------- settings
export function useAccountSettings() {
  const uid = useCurrentUserId();
  return sorted(rows(useTable('accountSettings')).filter((r: any) => r.userId === uid) as any);
}
export function useNotificationPreferences() {
  const uid = useCurrentUserId();
  return (rows(useTable('notificationPreferences')) as any[]).find((r) => r.userId === uid);
}

// ---------------------------------------------------------------- chat
export function useChatMessages(sessionId: string = IDS.chatSession) {
  return sorted(rows(useTable('chatMessages')).filter((r: any) => r.sessionId === sessionId) as any);
}
export function useNovaFallbackReplies() {
  return sorted(rows(useTable('novaFallbackReplies')) as any);
}

// ---------------------------------------------------------------- screen copy
export function useScreenCopy(screen: string) {
  const all = rows(useTable('screenCopy')) as any[];
  const map: Record<string, string> = {};
  for (const row of all) if (row.screen === screen) map[row.field] = row.value;
  return map;
}

// ---------------------------------------------------------------- intake
export function useNextIntakeBooking() {
  const uid = useCurrentUserId();
  return (rows(useTable('intakeBookings')) as any[]).find((b) => b.userId === uid && b.status !== 'cancelled');
}

// ---------------------------------------------------------------- mutations
export function useStoreMutations() {
  const store = useStore();
  return useMemo(
    () => ({
      toggleTaskCompletion(taskId: string, date: string, horseId: string) {
        if (!store) return;
        // Postgres requires `id` to be a UUID. Look up any existing
        // completion for this (taskId, date) by scanning the table; if found,
        // toggle its `done` field on the row's real id, otherwise INSERT a
        // fresh UUID row. The (task_id, date) unique constraint on the
        // server keeps things consistent.
        const all = store.getTable('protocolTaskCompletions') ?? {};
        const found = Object.entries(all).find(
          ([, r]: [string, any]) => r.taskId === taskId && r.date === date,
        );
        if (found) {
          const [existingId, row] = found as [string, any];
          const done = !row.done;
          store.setPartialRow('protocolTaskCompletions', existingId, {
            done,
            doneAt: done ? new Date().toISOString() : '',
          });
        } else {
          store.setRow('protocolTaskCompletions', newId(), {
            taskId,
            date,
            horseId,
            done: true,
            doneAt: new Date().toISOString(),
          });
        }
      },
      addHorseFocus(horseId: string, focusTopicId: string) {
        if (!store) return;
        // Idempotent: if a horseFocus row already links this (horseId,
        // focusTopicId), no-op so we don't violate the unique constraint
        // server-side.
        const existing = Object.values(store.getTable('horseFocus') ?? {}).some(
          (r: any) => r.horseId === horseId && r.focusTopicId === focusTopicId,
        );
        if (existing) return;
        store.setRow('horseFocus', newId(), {
          horseId,
          focusTopicId,
          addedAt: new Date().toISOString(),
          extraLabel: '',
        });
      },
      removeHorseFocus(horseId: string, focusTopicId: string) {
        if (!store) return;
        const found = Object.entries(store.getTable('horseFocus') ?? {}).find(
          ([, r]: [string, any]) => r.horseId === horseId && r.focusTopicId === focusTopicId,
        );
        if (found) store.delRow('horseFocus', found[0]);
      },
      setOnboarded(value: boolean) {
        if (!store) return;
        store.setValue('onboarded', value);
        if (value) {
          const uid = (store.getValue('currentUserId') as string) || IDS.user;
          store.setPartialRow('users', uid, { onboardedAt: new Date().toISOString() });
        }
      },
      addChatMessage(sessionId: string, role: 'user' | 'assistant', body: string) {
        if (!store) return;
        const existing = store.getTable('chatMessages');
        const order = Object.values(existing).filter((m: any) => m.sessionId === sessionId).length;
        const id = newId();
        void order; // kept for ordering field, no longer in id
        store.setRow('chatMessages', id, {
          sessionId,
          role,
          body,
          createdAt: new Date().toISOString(),
          order,
        });
        return id;
      },
      saveObservation(input: {
        horseId: string;
        authorId: string;
        note: string;
        mood: number;
        stoolScore: string;
      }) {
        if (!store) return;
        const id = newId();
        store.setRow('observations', id, {
          ...input,
          date: new Date().toISOString().slice(0, 10),
          createdAt: new Date().toISOString(),
        });
        return id;
      },
      upsertHorse(id: string, input: Record<string, any>) {
        if (!store) return;
        store.setRow('horses', id, input as any);
      },
      addPostReply(postId: string, body: string, authorUserId: string, authorName: string, authorInitial: string) {
        if (!store) return;
        const id = newId();
        const existing = store.getTable('communityReplies');
        const order = Object.values(existing).filter((r: any) => r.postId === postId).length;
        store.setRow('communityReplies', id, {
          postId,
          authorUserId,
          authorName,
          authorInitial,
          authorAvatarColor: '#5FD7CB',
          authorIsExpert: false,
          body,
          createdAt: new Date().toISOString(),
          whenLabel: 'zojuist',
          likesCount: 0,
          repliesCount: 0,
          order,
        });
        // bump repliesCount on parent
        const parent = store.getRow('communityPosts', postId);
        if (parent) {
          store.setPartialRow('communityPosts', postId, {
            repliesCount: ((parent.repliesCount as number) ?? 0) + 1,
          });
        }
      },
    }),
    [store],
  );
}

// Re-export raw hooks for one-off needs.
export { useCell, useRow, useTable, useRowIds, useValue, useStore, useLocalRowIds };
