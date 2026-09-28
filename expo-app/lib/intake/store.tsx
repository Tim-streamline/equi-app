// Offline answers belong to the selected horse's booking, which is also its intake.
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useQuery } from '@powersync/react';
import { Alert } from 'react-native';
import { FieldValue, IntakeAnswers } from './schema';
import { bookingQuery, ensureIntake, saveAnswer } from './persistence';
import { useDb } from '@/db/provider';
import { useCurrentUserId, useCurrentHorseId } from '@/db/hooks';

export type IntakeState = { answers: IntakeAnswers; submittedAt: string | null; savedAt: string | null };
const EMPTY: IntakeState = { answers: {}, submittedAt: null, savedAt: null };
type Ctx = {
  state: IntakeState; loaded: boolean;
  setField: (section: string, field: string, value: FieldValue) => void;
  resetSection: (section: string) => void;
  submit: () => Promise<void>; reset: () => void;
  ensureBooking: () => Promise<string>; horseId: string;
};
const IntakeContext = createContext<Ctx | null>(null);
const newId = () => typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); });
function decode(raw: string | null): FieldValue { try { return JSON.parse(raw ?? 'null'); } catch { return raw ?? ''; } }

export function IntakeProvider({ children }: { children: ReactNode }) {
  const userId = useCurrentUserId();
  const horseId = useCurrentHorseId();
  // An account/horse switch must never leave another horse's answers in the form.
  return <HorseIntakeProvider key={`${userId}:${horseId}`} userId={userId} horseId={horseId}>{children}</HorseIntakeProvider>;
}
function HorseIntakeProvider({ userId, horseId, children }: { userId: string; horseId: string; children: ReactNode }) {
  const { powersync } = useDb();
  const [state, setState] = useState<IntakeState>(EMPTY);
  const dirty = useRef(false);
  const queue = useRef<Promise<unknown>>(Promise.resolve());
  const { data: bookings, isLoading: bookingLoading } = useQuery<{ id: string; submitted_at: string | null; updated_at: string | null }>(bookingQuery, [userId, horseId]);
  const booking = bookings?.[0];
  const { data: answers, isLoading: answersLoading } = useQuery<{ section_id: string; field_id: string; value: string | null; updated_at: string }>(
    'SELECT section_id, field_id, value, updated_at FROM intake_answers WHERE response_id = ?', [booking?.id ?? '']);
  const loaded = !bookingLoading && (!booking || !answersLoading);
  useEffect(() => {
    if (dirty.current || !loaded) return;
    const next: IntakeAnswers = {};
    let savedAt = booking?.updated_at ?? null;
    for (const row of answers ?? []) {
      (next[row.section_id] ??= {})[row.field_id] = decode(row.value);
      if (!savedAt || row.updated_at > savedAt) savedAt = row.updated_at;
    }
    setState({ answers: next, submittedAt: booking?.submitted_at ?? null, savedAt });
  }, [bookings, answers, loaded, booking]);
  const ensureBooking = useCallback(() => ensureIntake(powersync, userId, horseId, newId), [powersync, userId, horseId]);
  const enqueue = useCallback((task: () => Promise<unknown>) => {
    const work = queue.current.then(task);
    queue.current = work.catch(error => { Alert.alert('Intake niet opgeslagen', error instanceof Error ? error.message : 'Probeer opnieuw.'); });
    return work;
  }, []);
  const setField = useCallback<Ctx['setField']>((section, field, value) => {
    dirty.current = true;
    setState(prev => ({ ...prev, answers: { ...prev.answers, [section]: { ...prev.answers[section], [field]: value } } }));
    void enqueue(async () => {
      const id = await ensureBooking();
      await saveAnswer(powersync, id, section, field, value, newId);
      setState(prev => ({ ...prev, savedAt: new Date().toISOString() }));
    }).catch(() => {});
  }, [enqueue, ensureBooking, powersync]);
  const resetSection = useCallback<Ctx['resetSection']>(section => {
    dirty.current = true;
    setState(prev => { const answers = { ...prev.answers }; delete answers[section]; return { ...prev, answers }; });
    void enqueue(async () => { const id = await ensureBooking(); await powersync.execute('DELETE FROM intake_answers WHERE response_id = ? AND section_id = ?', [id,section]); }).catch(() => {});
  }, [enqueue, ensureBooking, powersync]);
  const submit = useCallback(async () => {
    await queue.current;
    // Retry the complete current form before submission, including any failed local write.
    const id = await ensureBooking();
    await enqueue(async () => {
      for (const [section, values] of Object.entries(state.answers)) for (const [field, value] of Object.entries(values)) await saveAnswer(powersync, id, section, field, value, newId);
      const now = new Date().toISOString();
      await powersync.execute("UPDATE intake_bookings SET intake_status = 'submitted', submitted_at = ?, updated_at = ? WHERE id = ?", [now,now,id]);
      dirty.current = true;
      setState(prev => ({ ...prev, submittedAt: now, savedAt: now }));
    });
  }, [enqueue, ensureBooking, powersync, state.answers]);
  const reset = useCallback(() => {
    dirty.current = true;
    setState(EMPTY);
    void enqueue(async () => {
      const id = await ensureBooking();
      await powersync.writeTransaction(async tx => {
        await tx.execute('DELETE FROM intake_answers WHERE response_id = ?', [id]);
        await tx.execute("UPDATE intake_bookings SET intake_status = 'draft', submitted_at = NULL, updated_at = ? WHERE id = ?", [new Date().toISOString(), id]);
      });
    }).catch(() => {});
  }, [enqueue, ensureBooking, powersync]);
  const value = useMemo(() => ({ state, loaded, setField, resetSection, submit, reset, ensureBooking, horseId }), [state, loaded, setField, resetSection, submit, reset, ensureBooking, horseId]);
  return <IntakeContext.Provider value={value}>{children}</IntakeContext.Provider>;
}
export function useIntake(): Ctx { const context = useContext(IntakeContext); if (!context) throw new Error('useIntake must be inside IntakeProvider'); return context; }
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
