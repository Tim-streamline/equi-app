export type DateConfig = { mode?: 'single' | 'range'; allow_future?: boolean; allow_year_only?: boolean; min_year?: number; month_label?: string; year_label?: string; unknown_label?: string; ongoing_label?: string };
export type DatePoint = { version: 1; precision: 'month' | 'year' | 'day' | 'unknown' | 'legacy'; year?: number; month?: number; day?: number; text?: string };
export type DateAnswer = DatePoint | { version: 1; precision: 'range'; start?: DatePoint; end?: DatePoint; ongoing?: boolean };
export const MONTHS = ['Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni', 'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December'];
const monthNames = [...MONTHS.map(x => x.toLowerCase()), 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

/** Only unambiguous old answers are converted; all other text remains explicitly legacy. */
export function normalizeDate(value: unknown, config: DateConfig = {}): DateAnswer | undefined {
  if (value && typeof value === 'object' && !Array.isArray(value)) return value as DateAnswer;
  if (typeof value !== 'string' || !value.trim()) return undefined;
  const text = value.trim();
  const lower = text.toLowerCase();
  if (['onbekend', 'weet ik niet', 'weet niet', 'unknown'].includes(lower)) return { version: 1, precision: 'unknown' };
  if (config.mode === 'range') return { version: 1, precision: 'legacy', text };
  if (/^(19|20)\d{2}$/.test(text)) return { version: 1, precision: 'year', year: Number(text) };
  let match = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(text);
  if (match) {
    const [, y, m, d] = match;
    if (validDay(Number(y), Number(m), Number(d))) return { version: 1, precision: 'day', year: Number(y), month: Number(m), day: Number(d) };
  }
  match = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/.exec(text);
  if (match && validDay(Number(match[3]), Number(match[2]), Number(match[1]))) return { version: 1, precision: 'day', year: Number(match[3]), month: Number(match[2]), day: Number(match[1]) };
  match = /^(\d{4})-(\d{1,2})$/.exec(text);
  if (match && Number(match[2]) >= 1 && Number(match[2]) <= 12) return { version: 1, precision: 'month', year: Number(match[1]), month: Number(match[2]) };
  match = /^(\d{1,2})[-/](\d{4})$/.exec(text);
  if (match && Number(match[1]) >= 1 && Number(match[1]) <= 12) return { version: 1, precision: 'month', year: Number(match[2]), month: Number(match[1]) };
  match = /^([a-z]+)\s+(\d{4})$/.exec(lower);
  if (match && monthNames.includes(match[1])) return { version: 1, precision: 'month', year: Number(match[2]), month: monthNames.indexOf(match[1]) % 12 + 1 };
  return { version: 1, precision: 'legacy', text };
}
function validDay(year: number, month: number, day: number) {
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;
}
export function dateAnswered(raw: unknown, config: DateConfig = {}, now = new Date()): boolean {
  const value = normalizeDate(raw, config);
  if (!value || value.version !== 1) return false;
  if (value.precision === 'unknown') return true;
  if (value.precision === 'legacy') return typeof value.text === 'string' && !!value.text.trim();
  if (value.precision === 'range') {
    if (config.mode !== 'range') return false;
    if (!dateAnswered(value.start, { ...config, mode: 'single' }, now)) return false;
    if (value.ongoing === true) return true;
    if (!dateAnswered(value.end, { ...config, mode: 'single' }, now)) return false;
    return !value.start?.year || !value.end?.year || value.start.year * 12 + (value.start.month ?? 1) <= value.end.year * 12 + (value.end.month ?? 12);
  }
  if (config.mode === 'range') return false;
  if (!Number.isInteger(value.year) || value.year! < (config.min_year ?? 1900) || value.year! > 9999) return false;
  if (!['month', 'year', 'day'].includes(value.precision)) return false;
  if (value.precision === 'year' && config.allow_year_only === false) return false;
  if (value.precision !== 'year' && (!Number.isInteger(value.month) || value.month! < 1 || value.month! > 12)) return false;
  if (value.precision === 'day' && (!Number.isInteger(value.day) || !validDay(value.year!, value.month!, value.day!))) return false;
  if (!config.allow_future && (value.year! > now.getFullYear() || (value.year === now.getFullYear() && (value.month ?? 1) > now.getMonth() + 1))) return false;
  return true;
}
export function formatDate(raw: unknown): string {
  const v = normalizeDate(raw);
  if (!v) return '';
  if (v.precision === 'unknown') return 'Onbekend';
  if (v.precision === 'legacy') return v.text ?? '';
  if (v.precision === 'range') return `${formatDate(v.start)} – ${v.ongoing ? 'heden' : formatDate(v.end)}`;
  if (!v.year) return '';
  if (v.precision === 'year') return `${v.year} (maand onbekend)`;
  return `${v.precision === 'day' && v.day ? v.day + ' ' : ''}${MONTHS[(v.month ?? 0) - 1]?.toLowerCase() ?? ''} ${v.year}`.trim();
}
