// State for the Protocol Intake, backed by PowerSync (synced to the Laravel
// backend) instead of device-local AsyncStorage. The form lives in two synced
// tables:
//
//   intake_responses — one row per user (status draft|submitted, timestamps)
//   intake_answers    — one row per answered question, `value` is the
//                       JSON-encoded answer (scalar, multi array, or repeater
//                       rows). Decoded back into the `answers` map below.
//
// To keep typing smooth, the provider holds a local React-state mirror that
// updates synchronously on every keystroke and writes through to PowerSync.
// PowerSync rows are the source of truth across launches/devices: when they
// arrive (initial sync, another device) and the user hasn't made local edits
// yet (`dirtyRef`), the mirror is re-hydrated from them.
//
// The `useIntake()` API ({ state, loaded, setField, resetSection, submit,
// reset }) is unchanged from the previous AsyncStorage-backed implementation,
// so the intake screens consume it exactly as before.

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useQuery } from '@powersync/react';

import { FieldValue, IntakeAnswers, SectionAnswers } from './schema';
import { useDb } from '@/db/provider';
import { useCurrentUserId } from '@/db/hooks';

export type IntakeState = {
  /** Per-section answers keyed by section id. */
  answers: IntakeAnswers;
  /** ISO timestamp when the customer submitted the intake. */
  submittedAt: string | null;
  /** ISO timestamp of the most recent local mutation — drives "opgeslagen X min geleden". */
  savedAt: string | null;
};

const EMPTY: IntakeState = { answers: {}, submittedAt: null, savedAt: null };

type Ctx = {
  state: IntakeState;
  loaded: boolean;
  setField: (sectionId: string, fieldId: string, value: FieldValue) => void;
  resetSection: (sectionId: string) => void;
  submit: () => void;
  /** Wipe the saved intake — used when a new protocol starts. */
  reset: () => void;
};

const IntakeContext = createContext<Ctx | null>(null);

// RFC4122 v4 UUID — Postgres `uuid` columns reject anything else.
const newId = (): string =>
  typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
      });

const isEmptyValue = (v: FieldValue): boolean =>
  v === undefined || v === '' || (Array.isArray(v) && v.length === 0);

const encode = (v: FieldValue): string => JSON.stringify(v ?? null);

function decode(raw: unknown): FieldValue {
  if (typeof raw !== 'string') return raw as FieldValue;
  try {
    return JSON.parse(raw) as FieldValue;
  } catch {
    // Pre-JSON / corrupt value — fall back to the raw string.
    return raw;
  }
}

export function IntakeProvider({ children }: { children: ReactNode }) {
  const { powersync } = useDb();
  const userId = useCurrentUserId();

  const [state, setState] = useState<IntakeState>(EMPTY);
  const [loaded, setLoaded] = useState(false);

  // The id of the user's intake_responses row, cached so mutations don't need
  // to re-query it. Kept in sync with the hydration query + createResponse.
  const responseIdRef = useRef<string | null>(null);
  // Once the user edits, the local mirror wins and incoming PowerSync rows
  // (including our own write-throughs) stop clobbering it.
  const dirtyRef = useRef(false);
  // Tracks which user we've attempted the one-time AsyncStorage import for.
  const migratedRef = useRef<string | null>(null);

  // Reactive source-of-truth queries. `answerRows` re-runs when the response
  // id resolves. Using LIMIT 1 mirrors the one-intake-per-user model.
  const { data: respRows, isLoading: respLoading } = useQuery<{
    id: string;
    submitted_at: string | null;
    updated_at: string | null;
  }>(
    `SELECT id, submitted_at, updated_at FROM intake_responses WHERE user_id = ? LIMIT 1`,
    [userId],
  );
  const responseRow = respRows?.[0];
  const responseId = responseRow?.id ?? '';

  const { data: answerRows, isLoading: answersLoading } = useQuery<{
    section_id: string;
    field_id: string;
    value: string | null;
    updated_at: string | null;
  }>(
    `SELECT section_id, field_id, value, updated_at FROM intake_answers WHERE response_id = ?`,
    [responseId],
  );

  // Reset the per-user refs whenever the signed-in user changes.
  useEffect(() => {
    dirtyRef.current = false;
    responseIdRef.current = null;
    setLoaded(false);
  }, [userId]);

  // One-time import of a legacy AsyncStorage intake into PowerSync. Runs once
  // per user, only when no synced response exists yet.
  useEffect(() => {
    if (respLoading) return;
    if ((respRows?.length ?? 0) > 0) return;
    if (migratedRef.current === userId) return;
    migratedRef.current = userId;
    void migrateLegacyIntake(powersync, userId);
  }, [respLoading, respRows, userId, powersync]);

  // Adopt PowerSync rows into the local mirror — unless the user is mid-edit,
  // in which case the mirror is authoritative and we only flip `loaded`.
  useEffect(() => {
    const settled = !respLoading && (responseId ? !answersLoading : true);
    responseIdRef.current = responseRow?.id ?? null;

    if (dirtyRef.current) {
      if (settled) setLoaded(true);
      return;
    }

    const answers: IntakeAnswers = {};
    let maxUpdated: string | null = responseRow?.updated_at ?? null;
    for (const r of answerRows ?? []) {
      (answers[r.section_id] ??= {} as SectionAnswers)[r.field_id] = decode(r.value);
      if (r.updated_at && (!maxUpdated || r.updated_at > maxUpdated)) {
        maxUpdated = r.updated_at;
      }
    }
    setState({
      answers,
      submittedAt: responseRow?.submitted_at ?? null,
      savedAt: maxUpdated,
    });
    if (settled) setLoaded(true);
  }, [respRows, answerRows, respLoading, answersLoading, responseId, responseRow]);

  // Look up the response id (cached ref → live query), creating it on demand.
  const findResponseId = useCallback(async (): Promise<string | null> => {
    if (responseIdRef.current) return responseIdRef.current;
    const row = await powersync.getOptional<{ id: string }>(
      `SELECT id FROM intake_responses WHERE user_id = ? LIMIT 1`,
      [userId],
    );
    responseIdRef.current = row?.id ?? null;
    return responseIdRef.current;
  }, [powersync, userId]);

  const createResponse = useCallback(async (): Promise<string> => {
    const horse = await powersync.getOptional<{ id: string }>(
      `SELECT id FROM horses WHERE owner_id = ? AND status = 'active' ORDER BY created_at LIMIT 1`,
      [userId],
    );
    const now = new Date().toISOString();
    const id = newId();
    await powersync.execute(
      `INSERT INTO intake_responses
        (id, user_id, horse_id, status, started_at, submitted_at, created_at, updated_at)
       VALUES (?, ?, ?, 'draft', ?, NULL, ?, ?)`,
      [id, userId, horse?.id ?? null, now, now, now],
    );
    responseIdRef.current = id;
    return id;
  }, [powersync, userId]);

  const setField = useCallback<Ctx['setField']>(
    (sectionId, fieldId, value) => {
      dirtyRef.current = true;
      const empty = isEmptyValue(value);

      // Optimistic, synchronous mirror update so controlled inputs stay snappy.
      setState((prev) => {
        const nextAnswers: IntakeAnswers = { ...prev.answers };
        const sec: SectionAnswers = { ...(nextAnswers[sectionId] ?? {}) };
        if (empty) delete sec[fieldId];
        else sec[fieldId] = value;
        if (Object.keys(sec).length === 0) delete nextAnswers[sectionId];
        else nextAnswers[sectionId] = sec;
        return { ...prev, answers: nextAnswers, savedAt: new Date().toISOString() };
      });

      // Write through to PowerSync.
      void (async () => {
        try {
          let rid = await findResponseId();
          if (empty) {
            if (!rid) return;
            await powersync.execute(
              `DELETE FROM intake_answers WHERE response_id = ? AND section_id = ? AND field_id = ?`,
              [rid, sectionId, fieldId],
            );
            return;
          }
          if (!rid) rid = await createResponse();
          const now = new Date().toISOString();
          const existing = await powersync.getOptional<{ id: string }>(
            `SELECT id FROM intake_answers WHERE response_id = ? AND section_id = ? AND field_id = ? LIMIT 1`,
            [rid, sectionId, fieldId],
          );
          if (existing) {
            await powersync.execute(
              `UPDATE intake_answers SET value = ?, updated_at = ? WHERE id = ?`,
              [encode(value), now, existing.id],
            );
          } else {
            await powersync.execute(
              `INSERT INTO intake_answers
                (id, response_id, section_id, field_id, value, created_at, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?)`,
              [newId(), rid, sectionId, fieldId, encode(value), now, now],
            );
          }
        } catch (err) {
          console.warn('[intake] setField persist failed', err);
        }
      })();
    },
    [powersync, findResponseId, createResponse],
  );

  const resetSection = useCallback<Ctx['resetSection']>(
    (sectionId) => {
      dirtyRef.current = true;
      setState((prev) => {
        const nextAnswers = { ...prev.answers };
        delete nextAnswers[sectionId];
        return { ...prev, answers: nextAnswers, savedAt: new Date().toISOString() };
      });
      void (async () => {
        try {
          const rid = await findResponseId();
          if (!rid) return;
          await powersync.execute(
            `DELETE FROM intake_answers WHERE response_id = ? AND section_id = ?`,
            [rid, sectionId],
          );
        } catch (err) {
          console.warn('[intake] resetSection failed', err);
        }
      })();
    },
    [powersync, findResponseId],
  );

  const submit = useCallback<Ctx['submit']>(() => {
    dirtyRef.current = true;
    const now = new Date().toISOString();
    setState((prev) => ({ ...prev, submittedAt: now, savedAt: now }));
    void (async () => {
      try {
        const rid = (await findResponseId()) ?? (await createResponse());
        await powersync.execute(
          `UPDATE intake_responses SET status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ?`,
          [now, now, rid],
        );
      } catch (err) {
        console.warn('[intake] submit failed', err);
      }
    })();
  }, [powersync, findResponseId, createResponse]);

  const reset = useCallback<Ctx['reset']>(() => {
    dirtyRef.current = false;
    setState(EMPTY);
    void (async () => {
      try {
        const rid = await findResponseId();
        if (!rid) return;
        await powersync.execute(`DELETE FROM intake_answers WHERE response_id = ?`, [rid]);
        await powersync.execute(`DELETE FROM intake_responses WHERE id = ?`, [rid]);
        responseIdRef.current = null;
      } catch (err) {
        console.warn('[intake] reset failed', err);
      }
    })();
  }, [powersync, findResponseId]);

  const value = useMemo<Ctx>(
    () => ({ state, loaded, setField, resetSection, submit, reset }),
    [state, loaded, setField, resetSection, submit, reset],
  );

  return <IntakeContext.Provider value={value}>{children}</IntakeContext.Provider>;
}

export function useIntake(): Ctx {
  const ctx = useContext(IntakeContext);
  if (!ctx) throw new Error('useIntake must be used inside <IntakeProvider>');
  return ctx;
}

/**
 * One-time best-effort import of a legacy device-local intake (stored under
 * `intake:<userId>` in AsyncStorage by the previous implementation) into the
 * synced PowerSync tables. No-op when there's nothing to migrate or a synced
 * response already exists. The AsyncStorage key is cleared afterwards.
 */
async function migrateLegacyIntake(powersync: ReturnType<typeof useDb>['powersync'], userId: string) {
  const key = `intake:${userId}`;
  try {
    const raw = await AsyncStorage.getItem(key);
    if (!raw) return;
    const parsed = JSON.parse(raw) as Partial<IntakeState>;
    const answers = parsed.answers ?? {};
    const hasData = Object.keys(answers).length > 0 || !!parsed.submittedAt;
    if (!hasData) {
      await AsyncStorage.removeItem(key);
      return;
    }
    // Guard against duplicating an already-synced response.
    const existing = await powersync.getOptional<{ id: string }>(
      `SELECT id FROM intake_responses WHERE user_id = ? LIMIT 1`,
      [userId],
    );
    if (existing) {
      await AsyncStorage.removeItem(key);
      return;
    }

    const horse = await powersync.getOptional<{ id: string }>(
      `SELECT id FROM horses WHERE owner_id = ? AND status = 'active' ORDER BY created_at LIMIT 1`,
      [userId],
    );
    const now = new Date().toISOString();
    const savedAt = parsed.savedAt ?? now;
    const rid = newId();
    await powersync.execute(
      `INSERT INTO intake_responses
        (id, user_id, horse_id, status, started_at, submitted_at, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        rid,
        userId,
        horse?.id ?? null,
        parsed.submittedAt ? 'submitted' : 'draft',
        savedAt,
        parsed.submittedAt ?? null,
        savedAt,
        savedAt,
      ],
    );
    for (const [sectionId, sec] of Object.entries(answers)) {
      for (const [fieldId, value] of Object.entries(sec ?? {})) {
        if (isEmptyValue(value as FieldValue)) continue;
        await powersync.execute(
          `INSERT INTO intake_answers
            (id, response_id, section_id, field_id, value, created_at, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?)`,
          [newId(), rid, sectionId, fieldId, encode(value as FieldValue), savedAt, savedAt],
        );
      }
    }
    await AsyncStorage.removeItem(key);
  } catch (err) {
    console.warn('[intake] legacy migration failed', err);
  }
}

/** "Opgeslagen 2 min geleden"-style helper. Returns null when never saved. */
export function formatSavedAgo(savedAt: string | null, now: Date = new Date()): string | null {
  if (!savedAt) return null;
  const ts = new Date(savedAt).getTime();
  if (Number.isNaN(ts)) return null;
  const seconds = Math.max(0, Math.floor((now.getTime() - ts) / 1000));
  if (seconds < 30) return 'Net opgeslagen';
  if (seconds < 60) return `${seconds} sec geleden`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} min geleden`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} uur geleden`;
  const days = Math.floor(hours / 24);
  return `${days} dgn geleden`;
}
