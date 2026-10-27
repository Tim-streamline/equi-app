import { waitForIntakeUploads } from './submission';
import { preserveIntakeForRefresh } from './refresh';
import { registerRefreshProtection, refreshProtectionChanged } from '../refresh-safety';
import { accountSession } from '@/lib/account-session';
import { useGlobalSearchParams } from 'expo-router';
import { dashboardRequest } from '@/hooks/useHorseDashboard';
// Offline answers belong to the selected horse's booking, which is also its intake.
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useQuery } from '@powersync/react';
import { Alert } from 'react-native';
import { FieldValue, IntakeAnswers } from './schema';
import { bookingQuery, openOrCreateIntake, saveAnswer } from './persistence';
import { useDb } from '@/db/provider';
import { useCurrentUserId, useCurrentHorseId } from '@/db/hooks';
import { submissionBlocks as evaluateSubmissionBlocks, type SubmissionBlock } from './submission-blocks';
import { useIntakeSchema } from './schema-provider';

export type IntakeState = { answers: IntakeAnswers; submittedAt: string | null; savedAt: string | null };
const EMPTY: IntakeState = { answers: {}, submittedAt: null, savedAt: null };
type Ctx = {
  state: IntakeState; loaded: boolean; submitting: boolean;
  setField: (section: string, field: string, value: FieldValue) => void;
  resetSection: (section: string) => void;
  submit: () => Promise<void>; reset: () => void;
  trackActivity: (section: string, field?: string) => void;
  ensureBooking: () => Promise<string>; horseId: string; bookingId: string | null;
  submissionBlocks: SubmissionBlock[];
};
const IntakeContext = createContext<Ctx | null>(null);
const newId = () => typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); });
function decode(raw: string | null): FieldValue { try { return JSON.parse(raw ?? 'null'); } catch { return raw ?? ''; } }

export function IntakeProvider({ children }: { children: ReactNode }) {
  const userId = useCurrentUserId();
  const current = useCurrentHorseId();
  // While the horses list refreshes during a sync it can be empty for a moment. Keep the
  // horse the owner is working on, so the intake is not rebuilt and she keeps her place.
  const lastHorse = useRef<{ userId: string; horseId: string }>({ userId, horseId: current });
  if (current || lastHorse.current.userId !== userId) lastHorse.current = { userId, horseId: current };
  const horseId = lastHorse.current.horseId;
  // An account/horse switch must never leave another horse's answers in the form.
  return <IntakeSelection key={`${userId}:${horseId}`} userId={userId} horseId={horseId}>{children}</IntakeSelection>;
}
function IntakeSelection({ userId, horseId, children }: { userId: string; horseId: string; children: ReactNode }) {
  const { bookingId } = useGlobalSearchParams<{ bookingId?: string }>();
  const [selected, setSelected] = useState<string | null>(null);
  const requested = typeof bookingId === 'string' && /^[a-f0-9-]{36}$/i.test(bookingId) ? bookingId : null;
  useEffect(() => { if (requested) setSelected(requested); }, [requested]);
  const id = requested ?? selected;
  return <HorseIntakeProvider key={id ?? 'current'} userId={userId} horseId={horseId} selectedId={id}>{children}</HorseIntakeProvider>;
}
function HorseIntakeProvider({ userId, horseId, selectedId, children }: { userId: string; horseId: string; selectedId: string | null; children: ReactNode }) {
  const { powersync } = useDb();
  const { schema, noneOptions } = useIntakeSchema();
  const [state, setState] = useState<IntakeState>(EMPTY);
  const liveAnswers = useRef<IntakeAnswers>(state.answers);
  liveAnswers.current = state.answers;
  const dirty = useRef(false);
  const [submitting, setSubmitting] = useState(false);
  const locked = useRef(false);
  const mounted = useRef(true);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  const queue = useRef<Promise<unknown>>(Promise.resolve());
  const { data: bookings, isLoading: bookingLoading } = useQuery<{ id: string; submitted_at: string | null; completed_at: string | null; updated_at: string | null }>(selectedId ? 'SELECT id, submitted_at, completed_at, updated_at FROM intake_bookings WHERE user_id = ? AND horse_id = ? AND id = ? AND status != \'cancelled\'' : bookingQuery, selectedId ? [userId, horseId, selectedId] : [userId, horseId]);
  const booking = bookings?.[0];
  const { data: answers, isLoading: answersLoading } = useQuery<{ section_id: string; field_id: string; value: string | null; updated_at: string }>(
    'SELECT section_id, field_id, value, updated_at FROM intake_answers WHERE response_id = ?', [booking?.id ?? '']);
  const loaded = !bookingLoading && (!booking || !answersLoading);
  const completedAt = booking?.completed_at ?? booking?.submitted_at ?? null;
  const previousCompletion = useRef<{ id?: string; at: string | null }>({ at: null });
  if (completedAt || state.submittedAt) locked.current = true;
  useEffect(() => {
    if (!loaded) return;
    if (previousCompletion.current.id === booking?.id && previousCompletion.current.at && !completedAt) {
      // The server reopened this intake after removing obsolete answers.
      // Discard the old snapshot before accepting fresh answers.
      locked.current = false;
      dirty.current = false;
    }
    previousCompletion.current = { id: booking?.id, at: completedAt };
    if (completedAt) {
      locked.current = true;
      // Server completion wins over unsynced edits from another device.
      dirty.current = false;
    }
    if (dirty.current || !loaded) return;
    const next: IntakeAnswers = {};
    let savedAt = booking?.updated_at ?? null;
    for (const row of answers ?? []) {
      (next[row.section_id] ??= {})[row.field_id] = decode(row.value);
      if (!savedAt || row.updated_at > savedAt) savedAt = row.updated_at;
    }
    setState({ answers: next, submittedAt: completedAt, savedAt });
  }, [bookings, answers, loaded, booking, completedAt]);
  const authorizedBooking = useRef<Promise<string> | null>(null);
  const ensureBooking = useCallback(() => {
    if (booking?.id) return Promise.resolve(booking.id);
    if (selectedId) return Promise.reject(new Error('Deze intake wordt nog gesynchroniseerd of hoort niet bij dit paard.'));
    if (!authorizedBooking.current) authorizedBooking.current = openOrCreateIntake(powersync, userId, horseId, async () => {
      const { bookingId } = await dashboardRequest('/api/protocol-purchases/open', { horse_id: horseId });
      return bookingId;
    }).then(id => {
      if (booking?.id && booking.id !== id) throw new Error('De gekoppelde intake is gewijzigd. Open de intake opnieuw nadat de synchronisatie is voltooid.');
      return id;
    }).finally(() => { authorizedBooking.current = null; });
    return authorizedBooking.current;
  }, [powersync, userId, horseId, booking?.id, selectedId]);
  const trackActivity = useCallback((section: string, field?: string) => {
    void ensureBooking().then(id => dashboardRequest(`/api/intakes/${id}/activity`, { section, field })).catch(() => {});
  }, [ensureBooking]);
  const enqueue = useCallback((task: () => Promise<unknown>) => {
    const work = queue.current.then(task);
    queue.current = work.catch(error => { Alert.alert('Intake niet opgeslagen', error instanceof Error ? error.message : 'Probeer opnieuw.'); });
    return work;
  }, []);
  const setField = useCallback<Ctx['setField']>((section, field, value) => {
    if (locked.current) return;
    if (liveAnswers.current[section]?.[field] === value) return;
    liveAnswers.current = { ...liveAnswers.current, [section]: { ...liveAnswers.current[section], [field]: value } };
    dirty.current = true;
    const nextAnswers = liveAnswers.current;
    liveAnswers.current = nextAnswers;
    setState(prev => ({ ...prev, answers: nextAnswers }));
    void enqueue(async () => {
      const id = await ensureBooking();
      await saveAnswer(powersync, id, section, field, value, newId);
      setState(prev => ({ ...prev, savedAt: new Date().toISOString() }));
    }).catch(() => {});
  }, [enqueue, ensureBooking, powersync]);
  const submissionBlocks = useMemo(() => evaluateSubmissionBlocks(schema, state.answers, noneOptions), [schema, state.answers, noneOptions]);
  const resetSection = useCallback<Ctx['resetSection']>(section => {
    if (locked.current) return;
    dirty.current = true;
    const answers = { ...liveAnswers.current, [section]: {} };
    liveAnswers.current = answers;
    setState(prev => ({ ...prev, answers }));
    void enqueue(async () => {
      const id = await ensureBooking();
      await powersync.execute('DELETE FROM intake_answers WHERE response_id = ? AND section_id = ?', [id, section]);
    }).catch(() => {});
  }, [enqueue, ensureBooking, powersync]);
  const submit = useCallback(async () => {
    const blocks = evaluateSubmissionBlocks(schema, liveAnswers.current, noneOptions);
    if (blocks.length) throw new Error(blocks.map(block => block.message).join('\n'));
    if (state.submittedAt) return;
    if (locked.current) throw new Error('Je intake wordt al verstuurd.');
    locked.current = true;
    setSubmitting(true);
    const revision = accountSession.revision;
    const checkSession = () => {
      if (!mounted.current || revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd.');
    };
    try {
      await queue.current;
      checkSession();
      const id = await ensureBooking();
      // Retry any failed local save before waiting for the upload queue.
      for (const [section, values] of Object.entries(liveAnswers.current)) for (const [field, value] of Object.entries(values)) {
        checkSession();
        await saveAnswer(powersync, id, section, field, value, newId);
      }
      await waitForIntakeUploads(powersync, checkSession);
      checkSession();
      const result = await dashboardRequest(`/api/intakes/${id}/submit`, {});
      checkSession();
      dirty.current = true;
      setState(prev => ({ ...prev, submittedAt: result.completed_at, savedAt: result.completed_at }));
    } catch (error) {
      locked.current = !!completedAt;
      throw error;
    } finally {
      if (mounted.current) setSubmitting(false);
    }
  }, [ensureBooking, powersync, state.answers, state.submittedAt, completedAt, schema, noneOptions]);
  const reset = useCallback(() => {
    if (locked.current) return;
    dirty.current = true;
    const retained: IntakeAnswers = {};
    liveAnswers.current = retained;
    setState({ ...EMPTY, answers: retained });
    void enqueue(async () => {
      const id = await ensureBooking();
      await powersync.writeTransaction(async tx => {
        await tx.execute('DELETE FROM intake_answers WHERE response_id = ?', [id]);
        await tx.execute("UPDATE intake_bookings SET intake_status = 'draft', submitted_at = NULL, updated_at = ? WHERE id = ?", [new Date().toISOString(), id]);
      });
    }).catch(() => {});
  }, [enqueue, ensureBooking, powersync]);
  const refresh = useRef({ state, submitting, ensureBooking });
  refresh.current = { state, submitting, ensureBooking };
  useEffect(() => registerRefreshProtection({
    reason: () => refresh.current.submitting ? 'Je intake wordt verstuurd. Wacht tot dit klaar is.' : null,
    prepare: async () => {
      const revision = accountSession.revision;
      await preserveIntakeForRefresh({
        drain: async () => {
          let pending;
          do { pending = queue.current; await pending; } while (pending !== queue.current);
        },
        checkSession: () => {
          if (!mounted.current || revision !== accountSession.revision) throw new Error('Je sessie is gewijzigd. Probeer opnieuw.');
        },
        answersToSave: () => dirty.current && !refresh.current.state.submittedAt && !locked.current ? refresh.current.state.answers : null,
        ensureBooking: () => refresh.current.ensureBooking(),
        save: (id, section, field, value) => saveAnswer(powersync, id, section, field, value, newId),
      });
    },
  }), [powersync]);
  useEffect(() => { refreshProtectionChanged(); }, [submitting]);
  const bookingId = booking?.id ?? null;
  const value = useMemo(() => ({ state, loaded, submitting, setField, resetSection, submit, reset, ensureBooking, trackActivity, horseId, bookingId, submissionBlocks }), [state, loaded, submitting, setField, resetSection, submit, reset, ensureBooking, trackActivity, horseId, bookingId, submissionBlocks]);
  return <IntakeContext.Provider value={value}>{children}</IntakeContext.Provider>;
}
export function useIntake(): Ctx { const context = useContext(IntakeContext); if (!context) throw new Error('useIntake must be inside IntakeProvider'); return context; }
/** "Opgeslagen 2 min geleden"-style helper. Returns null when never saved. */
export function formatSavedAgo(savedAt: string | null, now: Date = new Date()): string | null {
  if (!savedAt) return null;
  // PowerSync can return Laravel's UTC timestamps without an explicit zone.
  const timestamp = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(savedAt)
    ? `${savedAt.replace(' ', 'T')}Z` : savedAt;
  const ts = new Date(timestamp).getTime();
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
