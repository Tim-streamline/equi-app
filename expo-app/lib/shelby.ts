import AsyncStorage from '@react-native-async-storage/async-storage';

export const SHELBY_NAME = 'Shelby';
export const SHELBY_SUBTITLE = 'Jouw persoonlijke paardenassistent';
export const SHELBY_INTRO = 'Hoi! Ik ben Shelby. Heb je een vraag over je paard? Ik denk graag met je mee!';
export const SHELBY_WELCOME = 'Hoi! Ik ben Shelby! 👋\nJe kunt bij mij terecht met al je vragen over de gezondheid, voeding en verzorging van je paard.\nWaar kan ik je vandaag mee helpen?';
/** Shelby "types" this long before her answer appears. */
export const SHELBY_TYPING_MS = 5000;
/** How long the one-time introduction stays next to the chat button. */
export const SHELBY_INTRO_MS = 8000;

const INTRO_KEY = 'equiapp:shelby-intro-seen';

/**
 * True only the very first time on this device. The introduction is marked as seen
 * as soon as it is shown, so closing the app early never shows it twice.
 */
export async function claimShelbyIntro(storage: Pick<typeof AsyncStorage, 'getItem' | 'setItem'> = AsyncStorage): Promise<boolean> {
  try {
    if (await storage.getItem(INTRO_KEY)) return false;
    await storage.setItem(INTRO_KEY, new Date().toISOString());
    return true;
  } catch {
    // Without storage we cannot promise "once", so stay quiet.
    return false;
  }
}

export const SHELBY_AI_NOTICE = 'Shelby is een AI-assistent. Ze stelt geen diagnoses en vervangt je dierenarts niet.';
export const SHELBY_UNAVAILABLE = 'Shelby is even niet bereikbaar. Probeer het zo opnieuw.';

export type ShelbyChatMessage = { id: string; role: 'user' | 'assistant'; body: string; plusCta: boolean };

/** Each calendar day starts a new chat; yesterday's conversation is not shown as today's. */
export function isSameLocalDay(iso: string | null | undefined, now: Date = new Date()): boolean {
  if (!iso) return false;
  const date = new Date(/[zZ]|[+-]\d\d:?\d\d$/.test(iso) ? iso : `${iso.replace(' ', 'T')}Z`);
  return !Number.isNaN(date.getTime()) && date.toDateString() === now.toDateString();
}

export function plusCtaFromMeta(meta: unknown): boolean {
  try {
    const value = typeof meta === 'string' ? JSON.parse(meta) : meta;
    return !!value && typeof value === 'object' && (value as { plus_cta?: unknown }).plus_cta === true;
  } catch {
    return false;
  }
}

/** Synced messages first, then answers that arrived from the server but have not synced yet. */
export function mergeShelbyMessages(synced: readonly Record<string, unknown>[], pending: readonly ShelbyChatMessage[]): ShelbyChatMessage[] {
  const rows: ShelbyChatMessage[] = synced.map(row => ({
    id: String(row.id), role: row.role === 'assistant' ? 'assistant' : 'user', body: String(row.body ?? ''),
    plusCta: plusCtaFromMeta(row.meta),
  }));
  const known = new Set(rows.map(row => row.id));
  return [...rows, ...pending.filter(message => !known.has(message.id))];
}
