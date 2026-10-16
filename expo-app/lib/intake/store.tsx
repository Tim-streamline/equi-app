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

export type IntakeState = { answers: IntakeAnswers; submittedAt: string | null; savedAt: string | null };
const EMPTY: IntakeState = { answers: {}, submittedAt: null, savedAt: null };
type Ctx = {
  state: IntakeState; loaded: boolean;
  setField: (section: string, field: string, value: FieldValue) => void;
  resetSection: (section: string) => void;
  submit: () => Promise<void>; reset: () => void;
  trackActivity: (section: string, field?: string) => void;
  ensureBooking: () => Promise<string>; horseId: string; bookingId: string | null;
};
const IntakeContext = createContext<Ctx | null>(null);
const newId = () => typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3) | 8).toString(16); });
function decode(raw: string | null): FieldValue { try { return JSON.parse(raw ?? 'null'); } catch { return raw ?? ''; } }

export function IntakeProvider({ children }: { children: ReactNode }) {
  const userId = useCurrentUserId();
  const horseId = useCurrentHorseId();
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
  const [state, setState] = useState<IntakeState>(EMPTY);
  const dirty = useRef(false);
  const queue = useRef<Promise<unknown>>(Promise.resolve());
  const { data: bookings, isLoading: bookingLoading } = useQuery<{ id: string; submitted_at: string | null; updated_at: string | null }>(selectedId ? 'SELECT id, submitted_at, updated_at FROM intake_bookings WHERE user_id = ? AND horse_id = ? AND id = ? AND status != \'cancelled\'' : bookingQuery, selectedId ? [userId, horseId, selectedId] : [userId, horseId]);
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
  const bookingId = booking?.id ?? null;
  const value = useMemo(() => ({ state, loaded, setField, resetSection, submit, reset, ensureBooking, trackActivity, horseId, bookingId }), [state, loaded, setField, resetSection, submit, reset, ensureBooking, trackActivity, horseId, bookingId]);
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
