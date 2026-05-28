// Local-only state for the Protocol Intake. Each user's answers live under
// the `intake:<userId>` key in AsyncStorage; once the form is submitted the
// `submittedAt` field freezes the answers and the entry screens stop offering
// to start a new one.
//
// Backed by a React Context so any screen can read / mutate without having to
// pass props down through the route stack. Writes are persisted on every
// mutation (small JSON blob, fine for our scale).

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

import {
  FieldValue,
  IntakeAnswers,
  SectionAnswers,
} from './schema';
import { useCurrentUserId } from '@/db/hooks';

export type IntakeState = {
  /** Per-section answers keyed by section id. */
  answers: IntakeAnswers;
  /** ISO timestamp when the customer hit "Verzenden naar Shelley". */
  submittedAt: string | null;
  /** ISO timestamp of the most recent local mutation — drives "opgeslagen X min geleden". */
  savedAt: string | null;
};

const EMPTY: IntakeState = { answers: {}, submittedAt: null, savedAt: null };

const storageKey = (userId: string) => `intake:${userId}`;

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

export function IntakeProvider({ children }: { children: ReactNode }) {
  const userId = useCurrentUserId();
  const [state, setState] = useState<IntakeState>(EMPTY);
  const [loaded, setLoaded] = useState(false);
  // Track the *active* userId so we don't write back the previous user's state
  // into the next user's storage key during the cross-user transition.
  const activeUserRef = useRef<string>(userId);

  // Re-hydrate whenever the active user changes (login / logout / switch).
  useEffect(() => {
    activeUserRef.current = userId;
    let cancelled = false;
    setLoaded(false);
    (async () => {
      try {
        const raw = await AsyncStorage.getItem(storageKey(userId));
        if (cancelled) return;
        if (raw) {
          const parsed = JSON.parse(raw) as Partial<IntakeState>;
          setState({
            answers: parsed.answers ?? {},
            submittedAt: parsed.submittedAt ?? null,
            savedAt: parsed.savedAt ?? null,
          });
        } else {
          setState(EMPTY);
        }
      } catch (err) {
        console.warn('[intake] load failed', err);
        setState(EMPTY);
      } finally {
        if (!cancelled) setLoaded(true);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [userId]);

  const persist = useCallback((next: IntakeState) => {
    const uid = activeUserRef.current;
    AsyncStorage.setItem(storageKey(uid), JSON.stringify(next)).catch((err) => {
      console.warn('[intake] save failed', err);
    });
  }, []);

  const setField = useCallback<Ctx['setField']>(
    (sectionId, fieldId, value) => {
      setState((prev) => {
        const sec: SectionAnswers = { ...(prev.answers[sectionId] ?? {}) };
        if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
          delete sec[fieldId];
        } else {
          sec[fieldId] = value;
        }
        const next: IntakeState = {
          ...prev,
          answers: { ...prev.answers, [sectionId]: sec },
          savedAt: new Date().toISOString(),
        };
        persist(next);
        return next;
      });
    },
    [persist],
  );

  const resetSection = useCallback<Ctx['resetSection']>(
    (sectionId) => {
      setState((prev) => {
        const next: IntakeState = {
          ...prev,
          answers: { ...prev.answers, [sectionId]: {} },
          savedAt: new Date().toISOString(),
        };
        persist(next);
        return next;
      });
    },
    [persist],
  );

  const submit = useCallback<Ctx['submit']>(() => {
    setState((prev) => {
      const ts = new Date().toISOString();
      const next: IntakeState = { ...prev, submittedAt: ts, savedAt: ts };
      persist(next);
      return next;
    });
  }, [persist]);

  const reset = useCallback<Ctx['reset']>(() => {
    const next = EMPTY;
    setState(next);
    persist(next);
  }, [persist]);

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

/** Convenience accessor for a single section's answers. */
export function useSectionAnswers(sectionId: string): SectionAnswers {
  const { state } = useIntake();
  return state.answers[sectionId] ?? {};
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
