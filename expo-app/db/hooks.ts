// Typed selectors over the on-device PowerSync SQLite database. Each hook is
// a thin wrapper around @powersync/react's `useQuery` that converts the
// snake_case rows PowerSync returns into the camelCase shape the UI consumes.
//
// Mutations call `powersync.execute(...)` directly against the synced VIEWs —
// PowerSync's auto-generated INSTEAD-OF triggers handle local persistence and
// queue the change for upload via `LaravelConnector.uploadData()`.

import { useMemo } from 'react';
import { useQuery, usePowerSync } from '@powersync/react';
import { APP_STRINGS } from './app-strings';
import { IDS } from './ids';
import { useDb } from './provider';

// RFC4122 v4 UUID — Postgres `uuid` columns reject anything else.
const newId = (): string =>
  typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
      });

type Indexed = { id: string; [key: string]: any };

const snakeToCamelKey = (k: string): string =>
  k.replace(/_([a-z])/g, (_, c) => c.toUpperCase());

// SQLite returns 0/1 for boolean-ish columns; the UI expects real booleans
// for cells like done/verified/isFeatured etc. so we coerce by column name.
const BOOLEAN_KEYS = new Set([
  'notificationsOn', 'verified', 'isNow', 'bookmarked', 'isPlus', 'isFeatured',
  'isDefault', 'completed', 'active', 'hasExpertReply', 'authorIsExpert',
  'isRecommended', 'reminderProtocol', 'reminderCommunity',
  'reminderSeasonalTips', 'done',
]);

function camelRow(row: Record<string, any>): Indexed {
  const out: any = {};
  for (const k in row) {
    const v = row[k];
    if (v === null || v === undefined) continue;
    const ck = snakeToCamelKey(k);
    out[ck] = BOOLEAN_KEYS.has(ck) ? v === 1 || v === true || v === '1' : v;
  }
  return out as Indexed;
}

function camelRows(rows: ReadonlyArray<Record<string, any>>): Indexed[] {
  return rows.map(camelRow);
}

// `data` from useQuery is always defined (empty array while loading), so
// memoizing the camel-cased copy avoids re-mapping on every render unless
// the result reference changes.
function useCamelQuery(sql: string, params: any[] = []): Indexed[] {
  const { data } = useQuery(sql, params);
  return useMemo(() => camelRows(data ?? []), [data]);
}

function sorted(list: Indexed[]): Indexed[] {
  return [...list].sort((a, b) => ((a.order as number) ?? 0) - ((b.order as number) ?? 0));
}

// ---------------------------------------------------------------- identity
export function useCurrentUserId(): string {
  const { currentUserId } = useDb();
  return currentUserId || IDS.user;
}

export function useCurrentHorseId(): string {
  // Derive: first active horse owned by the current user.
  const uid = useCurrentUserId();
  const rows = useCamelQuery(
    `SELECT id FROM horses WHERE owner_id = ? AND status = 'active' ORDER BY created_at LIMIT 1`,
    [uid],
  );
  return (rows[0]?.id as string) || IDS.horse;
}

export function useCurrentUser(): Indexed {
  const id = useCurrentUserId();
  const rows = useCamelQuery(`SELECT * FROM users WHERE id = ?`, [id]);
  return { ...(rows[0] ?? {}), id } as Indexed;
}

export function useTherapist(id: string = IDS.therapist): Indexed {
  const rows = useCamelQuery(`SELECT * FROM therapists WHERE id = ?`, [id]);
  return { ...(rows[0] ?? {}), id } as Indexed;
}

// ---------------------------------------------------------------- horses
export function useHorse(id?: string): Indexed {
  const fallback = useCurrentHorseId();
  const horseId = id ?? fallback;
  const rows = useCamelQuery(`SELECT * FROM horses WHERE id = ?`, [horseId]);
  return { ...(rows[0] ?? {}), id: horseId } as Indexed;
}

export function useHorsesByOwner(ownerId?: string): Indexed[] {
  const uid = useCurrentUserId();
  const target = ownerId ?? uid;
  return useCamelQuery(`SELECT * FROM horses WHERE owner_id = ?`, [target]);
}

export function useFocusForHorse(horseId?: string) {
  const fallback = useCurrentHorseId();
  const id = horseId ?? fallback;
  const joins = useCamelQuery(
    `SELECT hf.*, ft.id AS topic_id, ft.icon AS topic_icon, ft.title AS topic_title,
            ft.description AS topic_description, ft."order" AS topic_order
     FROM horse_focus hf
     LEFT JOIN focus_topics ft ON ft.id = hf.focus_topic_id
     WHERE hf.horse_id = ?`,
    [id],
  );
  return joins.map((j: any) => ({
    id: j.id,
    horseId: j.horseId,
    focusTopicId: j.focusTopicId,
    extraLabel: j.extraLabel,
    topic: {
      id: j.topicId,
      icon: j.topicIcon,
      title: j.topicTitle,
      description: j.topicDescription,
      order: j.topicOrder,
    } as Indexed,
  }));
}

export function useFocusTopics() {
  return sorted(useCamelQuery(`SELECT * FROM focus_topics`));
}

export function useHorseShares(horseId?: string) {
  const fallback = useCurrentHorseId();
  const id = horseId ?? fallback;
  const shares = useCamelQuery(`SELECT * FROM horse_shares WHERE horse_id = ?`, [id]);
  const userIds = shares.map((s) => s.granteeUserId).filter(Boolean);
  const therapistIds = shares.map((s) => s.therapistId).filter(Boolean);
  const placeholdersU = userIds.length ? userIds.map(() => '?').join(',') : 'NULL';
  const placeholdersT = therapistIds.length ? therapistIds.map(() => '?').join(',') : 'NULL';
  const users = useCamelQuery(
    `SELECT * FROM users WHERE id IN (${placeholdersU})`,
    userIds,
  );
  const therapists = useCamelQuery(
    `SELECT * FROM therapists WHERE id IN (${placeholdersT})`,
    therapistIds,
  );
  return shares.map((r) => ({
    ...r,
    user: r.granteeUserId ? users.find((u) => u.id === r.granteeUserId) ?? null : null,
    therapist: r.therapistId ? therapists.find((t) => t.id === r.therapistId) ?? null : null,
  }));
}

export function useHorseStats(horseId?: string) {
  const fallback = useCurrentHorseId();
  const id = horseId ?? fallback;
  return sorted(useCamelQuery(`SELECT * FROM horse_stats WHERE horse_id = ?`, [id]));
}

export function useTimeline(horseId?: string) {
  const fallback = useCurrentHorseId();
  const id = horseId ?? fallback;
  return sorted(useCamelQuery(`SELECT * FROM timeline_events WHERE horse_id = ?`, [id]));
}

// ---------------------------------------------------------------- protocol
export function useActiveProtocolForHorse(horseId?: string) {
  const fallback = useCurrentHorseId();
  const id = horseId ?? fallback;
  const rows = useCamelQuery(
    `SELECT * FROM protocols WHERE horse_id = ? AND status = 'active' LIMIT 1`,
    [id],
  );
  return rows[0] ?? null;
}

export function useProtocolPhases(protocolId: string) {
  return sorted(useCamelQuery(`SELECT * FROM protocol_phases WHERE protocol_id = ?`, [protocolId]));
}

export function usePhaseItems(phaseId: string) {
  return sorted(useCamelQuery(`SELECT * FROM protocol_phase_items WHERE phase_id = ?`, [phaseId]));
}

export function useProtocolAnalysis(protocolId: string): (Indexed & { advice: Indexed[] }) | null {
  const analyses = useCamelQuery(`SELECT * FROM protocol_analyses WHERE protocol_id = ? LIMIT 1`, [protocolId]);
  const analysis = analyses[0];
  const advice = sorted(
    useCamelQuery(`SELECT * FROM protocol_advice WHERE analysis_id = ?`, [analysis?.id ?? '']),
  );
  return analysis ? { ...analysis, advice } : null;
}

export function useProtocolTasks(protocolId: string) {
  return sorted(useCamelQuery(`SELECT * FROM protocol_tasks WHERE protocol_id = ?`, [protocolId]));
}

export function useTaskCompletionsForDate(date: string) {
  return useCamelQuery(`SELECT * FROM protocol_task_completions WHERE date = ?`, [date]);
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
  return useCamelQuery(`SELECT * FROM scan_results`);
}
export function useScanResult(id: string): Indexed {
  const rows = useCamelQuery(`SELECT * FROM scan_results WHERE id = ?`, [id]);
  return { ...(rows[0] ?? {}), id } as Indexed;
}
export function useScanIngredients(scanId: string) {
  return sorted(useCamelQuery(`SELECT * FROM scan_ingredients WHERE scan_id = ?`, [scanId]));
}

// ---------------------------------------------------------------- library
export function useLibraryItems() {
  return sorted(useCamelQuery(`SELECT * FROM library_items`));
}
export function useLibraryFeatured() {
  const rows = useCamelQuery(`SELECT * FROM library_items WHERE is_featured = 1 LIMIT 1`);
  return rows[0];
}
export function useLibraryItem(id: string): Indexed {
  const rows = useCamelQuery(`SELECT * FROM library_items WHERE id = ?`, [id]);
  return { ...(rows[0] ?? {}), id } as Indexed;
}
export function useLibraryChapters(itemId: string) {
  return sorted(useCamelQuery(`SELECT * FROM library_chapters WHERE item_id = ?`, [itemId]));
}
export function useLibraryArticleSections(itemId: string) {
  return sorted(useCamelQuery(`SELECT * FROM library_article_sections WHERE item_id = ?`, [itemId]));
}
export function useLibraryCategories() {
  return sorted(useCamelQuery(`SELECT * FROM library_categories`));
}
export function useActiveSeasonalTip() {
  const rows = useCamelQuery(`SELECT * FROM seasonal_tips WHERE active = 1 LIMIT 1`);
  return rows[0];
}

// ---------------------------------------------------------------- community
export function useCommunityPosts() {
  return sorted(useCamelQuery(`SELECT * FROM community_posts`));
}
export function useCommunityPost(id: string): Indexed {
  const rows = useCamelQuery(`SELECT * FROM community_posts WHERE id = ?`, [id]);
  return { ...(rows[0] ?? {}), id } as Indexed;
}
export function useCommunityReplies(postId: string) {
  return sorted(useCamelQuery(`SELECT * FROM community_replies WHERE post_id = ?`, [postId]));
}
export function useCommunityCategories() {
  return sorted(useCamelQuery(`SELECT * FROM community_categories`));
}
export function useCommunityPostTags(postId: string) {
  return sorted(
    useCamelQuery(
      `SELECT pt.*, t.label
       FROM community_post_tags pt
       LEFT JOIN community_tags t ON t.id = pt.tag_id
       WHERE pt.post_id = ?`,
      [postId],
    ),
  );
}

// ---------------------------------------------------------------- subscription
export function useActiveSubscription() {
  const uid = useCurrentUserId();
  const rows = useCamelQuery(
    `SELECT * FROM subscriptions WHERE user_id = ? AND status = 'active' LIMIT 1`,
    [uid],
  );
  return rows[0];
}
export function usePlanBenefits(planId: string) {
  return sorted(useCamelQuery(`SELECT * FROM plan_benefits WHERE plan_id = ?`, [planId]));
}
export function usePayments(subscriptionId: string) {
  return sorted(useCamelQuery(`SELECT * FROM payments WHERE subscription_id = ?`, [subscriptionId]));
}
export function usePlan(id: string): Indexed {
  const rows = useCamelQuery(`SELECT * FROM plans WHERE id = ?`, [id]);
  return { ...(rows[0] ?? {}), id } as Indexed;
}
export function usePlans() {
  return sorted(useCamelQuery(`SELECT * FROM plans`));
}
export function useAllTaskCompletions() {
  return useCamelQuery(`SELECT * FROM protocol_task_completions`);
}

// ---------------------------------------------------------------- settings
export function useAccountSettings() {
  const uid = useCurrentUserId();
  return sorted(useCamelQuery(`SELECT * FROM account_settings WHERE user_id = ?`, [uid]));
}
export function useNotificationPreferences() {
  const uid = useCurrentUserId();
  const rows = useCamelQuery(`SELECT * FROM notification_preferences WHERE user_id = ? LIMIT 1`, [uid]);
  return rows[0];
}

// ---------------------------------------------------------------- chat
export function useChatMessages(sessionId: string = IDS.chatSession) {
  return sorted(useCamelQuery(`SELECT * FROM chat_messages WHERE session_id = ?`, [sessionId]));
}
export function useNovaFallbackReplies() {
  return sorted(useCamelQuery(`SELECT * FROM nova_fallback_replies`));
}

// ---------------------------------------------------------------- screen copy
export function useScreenCopy(screen: string) {
  const rows = useCamelQuery(`SELECT field, value FROM screen_copy WHERE screen = ?`, [screen]);
  const map: Record<string, string> = {};
  for (const row of rows) map[row.field] = row.value;
  return map;
}

// ---------------------------------------------------------------- intake
export function useNextIntakeBooking() {
  const uid = useCurrentUserId();
  const rows = useCamelQuery(
    `SELECT * FROM intake_bookings WHERE user_id = ? AND status != 'cancelled' LIMIT 1`,
    [uid],
  );
  return rows[0];
}

// ---------------------------------------------------------- non-synced values
// Previously these lived as TinyBase Values; now they're static strings
// since none of them ever changed at runtime.
export function useValue(name: string): string | number | boolean | undefined {
  return APP_STRINGS[name];
}

// ---------------------------------------------------------------- mutations
export function useStoreMutations() {
  const powersync = usePowerSync();
  return useMemo(
    () => ({
      async toggleTaskCompletion(taskId: string, date: string, horseId: string) {
        // Look up any existing completion synchronously; if found toggle in
        // place, otherwise INSERT a fresh UUID row. The (task_id, date)
        // unique constraint on the server keeps things consistent.
        const existing = await powersync.getOptional<{ id: string; done: number }>(
          `SELECT id, done FROM protocol_task_completions WHERE task_id = ? AND date = ? LIMIT 1`,
          [taskId, date],
        );
        if (existing) {
          const next = existing.done ? 0 : 1;
          await powersync.execute(
            `UPDATE protocol_task_completions SET done = ?, done_at = ? WHERE id = ?`,
            [next, next ? new Date().toISOString() : '', existing.id],
          );
        } else {
          await powersync.execute(
            `INSERT INTO protocol_task_completions (id, task_id, horse_id, date, done, done_at)
             VALUES (?, ?, ?, ?, 1, ?)`,
            [newId(), taskId, horseId, date, new Date().toISOString()],
          );
        }
      },
      async addHorseFocus(horseId: string, focusTopicId: string) {
        const existing = await powersync.getOptional<{ id: string }>(
          `SELECT id FROM horse_focus WHERE horse_id = ? AND focus_topic_id = ? LIMIT 1`,
          [horseId, focusTopicId],
        );
        if (existing) return;
        await powersync.execute(
          `INSERT INTO horse_focus (id, horse_id, focus_topic_id, added_at, extra_label)
           VALUES (?, ?, ?, ?, '')`,
          [newId(), horseId, focusTopicId, new Date().toISOString()],
        );
      },
      async removeHorseFocus(horseId: string, focusTopicId: string) {
        await powersync.execute(
          `DELETE FROM horse_focus WHERE horse_id = ? AND focus_topic_id = ?`,
          [horseId, focusTopicId],
        );
      },
      async setOnboarded(value: boolean, userId: string) {
        if (!value || !userId) return;
        await powersync.execute(
          `UPDATE users SET onboarded_at = ? WHERE id = ?`,
          [new Date().toISOString(), userId],
        );
      },
      async addChatMessage(sessionId: string, role: 'user' | 'assistant', body: string) {
        const id = newId();
        const orderRow = await powersync.getOptional<{ c: number }>(
          `SELECT COUNT(*) AS c FROM chat_messages WHERE session_id = ?`,
          [sessionId],
        );
        const order = orderRow?.c ?? 0;
        await powersync.execute(
          `INSERT INTO chat_messages (id, session_id, role, body, created_at, "order")
           VALUES (?, ?, ?, ?, ?, ?)`,
          [id, sessionId, role, body, new Date().toISOString(), order],
        );
        return id;
      },
      async saveObservation(input: {
        horseId: string;
        authorId: string;
        note: string;
        mood: number;
        stoolScore: string;
      }) {
        const id = newId();
        await powersync.execute(
          `INSERT INTO observations (id, horse_id, author_id, note, mood, stool_score, date)
           VALUES (?, ?, ?, ?, ?, ?, ?)`,
          [
            id,
            input.horseId,
            input.authorId,
            input.note,
            input.mood,
            input.stoolScore,
            new Date().toISOString().slice(0, 10),
          ],
        );
        return id;
      },
      async upsertHorse(id: string, input: Record<string, any>) {
        // Callers pass camelCase keys (matching the rest of the UI). Convert
        // to snake_case column names for the SQL.
        const camelToSnake = (k: string) => k.replace(/[A-Z]/g, (c) => `_${c.toLowerCase()}`);
        const inputCols = Object.keys(input).map(camelToSnake);
        const inputVals = Object.values(input);
        const existing = await powersync.getOptional<{ id: string }>(
          `SELECT id FROM horses WHERE id = ? LIMIT 1`,
          [id],
        );
        if (existing) {
          const setClause = inputCols.map((c) => `"${c}" = ?`).join(', ');
          await powersync.execute(
            `UPDATE horses SET ${setClause} WHERE id = ?`,
            [...inputVals, id],
          );
        } else {
          const allCols = ['id', ...inputCols];
          const colList = allCols.map((c) => `"${c}"`).join(',');
          const placeholders = allCols.map(() => '?').join(',');
          await powersync.execute(
            `INSERT INTO horses (${colList}) VALUES (${placeholders})`,
            [id, ...inputVals],
          );
        }
      },
      async addPostReply(
        postId: string,
        body: string,
        authorUserId: string,
        authorName: string,
        authorInitial: string,
      ) {
        const id = newId();
        const orderRow = await powersync.getOptional<{ c: number }>(
          `SELECT COUNT(*) AS c FROM community_replies WHERE post_id = ?`,
          [postId],
        );
        const order = orderRow?.c ?? 0;
        await powersync.execute(
          `INSERT INTO community_replies
           (id, post_id, author_user_id, author_name, author_initial,
            author_avatar_color, author_is_expert, body, created_at,
            when_label, likes_count, replies_count, "order")
           VALUES (?, ?, ?, ?, ?, '#5FD7CB', 0, ?, ?, 'zojuist', 0, 0, ?)`,
          [id, postId, authorUserId, authorName, authorInitial, body, new Date().toISOString(), order],
        );
        // Bump repliesCount on the parent post.
        await powersync.execute(
          `UPDATE community_posts SET replies_count = COALESCE(replies_count, 0) + 1 WHERE id = ?`,
          [postId],
        );
      },
    }),
    [powersync],
  );
}
