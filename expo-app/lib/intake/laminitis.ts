import type { IntakeAnswers, Section } from './schema';

export type LaminitisState = { episode_id?: string; kind?: 'acute' | 'unresolved'; client_marker?: string; released_at?: string };
export const RECOVERY_FIELDS = ['hoefbevangenheid-acute-fase-voorbij', 'hoefbevangenheid-stabiel'];
export const ACUTE = ['nu acuut', 'Ja, mijn paard is momenteel hoefbevangen'];
export const PAST = ['in verleden gehad', 'Ja, mijn paard is in het verleden hoefbevangen geweest'];
export function acuteChoices(schema: Section[] = []) {
  const values = schema.find(s => s.id === 'medisch')?.fields.find(f => f.id === 'hoefbevangenheid')?.criticalIf;
  return [...ACUTE, ...(Array.isArray(values) ? values : values ? [values] : [])];
}
export function pastChoices(schema: Section[] = []) {
  const condition = schema.find(s => s.id === 'medisch')?.fields.find(f => f.id === 'hoefbevangenheid-hersteld')?.showIf?.hoefbevangenheid;
  return [...PAST, ...(Array.isArray(condition) ? condition : condition ? [condition] : [])];
}
export function laminitisHold(answers: IntakeAnswers, safety: LaminitisState | null, schema: Section[] = []) {
  const values = answers.medisch ?? {};
  const pending = !!values._laminitis_hold && values._laminitis_hold !== safety?.client_marker;
  return pending || (!!safety?.episode_id && !safety.released_at)
    || (!safety?.released_at && acuteChoices(schema).includes(String(values.hoefbevangenheid)))
    || (pastChoices(schema).includes(String(values.hoefbevangenheid)) && ['Nee', 'Weet ik niet'].includes(String(values['hoefbevangenheid-hersteld'])));
}
export function laminitisNotice(schema: Section[], safety: LaminitisState | null) {
  const key = safety?.kind === 'unresolved' ? 'hoefbevangenheid-aanhoudend' : 'hoefbevangenheid-waarschuwing';
  return schema.find(s => s.id === 'medisch')?.fields.find(f => f.id === key);
}
