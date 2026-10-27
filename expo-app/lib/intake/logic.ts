// Pure evaluators over the intake schema + an answer set. Used to drive
// conditional rendering (showIf), aggregate the therapist's review chips
// (flagIf / criticalIf), and decide when a section is "done".

import {
  Field,
  FieldValue,
  IntakeAnswers,
  INTAKE_NONE_OPTIONS,
  INTAKE_SCHEMA,
  Section,
  SectionAnswers,
  Trigger,
} from './schema';
import { isConfiguredNoneOption } from './config';
import { dateAnswered } from './dates';

/** Resolve moved questions without rewriting stored answers or upload references. */
export function answersForSection(section: Section, answers: IntakeAnswers): SectionAnswers {
  const result = { ...(answers[section.id] ?? {}) };
  for (const field of section.fields) {
    const source = answers[field.answerSection ?? section.id];
    if (source && Object.prototype.hasOwnProperty.call(source, field.id)) result[field.id] = normalizeAnswer(field, source[field.id]);
  }
  return result;
}

/** Field-level "the answer is non-empty" check used for `flagIf: 'non-empty'`. */
export function isEmpty(v: unknown): boolean {
  if (v == null) return true;
  if (typeof v === 'string') return v.trim() === '';
  if (Array.isArray(v)) return v.length === 0;
  return false;
}

function matches(value: unknown, trigger: Trigger): boolean {
  const set = Array.isArray(trigger) ? trigger : [trigger];
  if (Array.isArray(value)) return value.some((v) => set.includes(String(v)));
  if (value == null || value === '') return false;
  return set.includes(String(value));
}

/**
 * Whether a multi-option label is a "none / not-applicable" sentinel — these
 * are mutually exclusive with every other option and never count as a real
 * flag/"any-checked" answer. Generalized from the original literal `'geen'`
 * to also cover "Geen van onderstaande", "Geen andere diersoorten",
 * "Nee, nooit", etc. that the deel-2 spec introduced.
 */
export function isNoneOption(
  option: string,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): boolean {
  // Explicit allowlist rather than a `startsWith('geen')` rule: several
  // legitimate, combinable traits also start with "Geen" (e.g. "Geen actief
  // beheer", "Geen duidelijke klachten, maar…") and must NOT clear the rest.
  return isConfiguredNoneOption(option, noneOptions);
}

/** Whether the field/value indicates "every question is mandatory" gating. */
export function isFieldRequired(field: Field): boolean {
  if (field.optional) return false;
  if (field.type === 'sectionhead') return false;
  return true;
}

/** Strict calendar parsing, accepting legacy ISO and Dutch dates. No invented dates. */
export function ageFromBirthdate(value: unknown, today = new Date()): number | undefined {
  if (typeof value !== 'string') return undefined;
  const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());
  const dutch = /^(\d{1,2})-(\d{1,2})-(\d{4})$/.exec(value.trim());
  if (!iso && !dutch) return undefined;
  const [year, month, day] = iso ? iso.slice(1).map(Number) : [Number(dutch![3]), Number(dutch![2]), Number(dutch![1])];
  const date = new Date(year, month - 1, day);
  if (year < 1000 || date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day || date > today) return undefined;
  return today.getFullYear() - year - (today.getMonth() < month - 1 || (today.getMonth() === month - 1 && today.getDate() < day) ? 1 : 0);
}

/**
 * Whether a field counts as genuinely answered. Stricter than `!isEmpty` for
 * option-based fields: a value is only valid if it still matches the field's
 * current `options`. This keeps the "X / Y vragen beantwoord" tally (and the
 * completion check) honest when persisted answers predate a schema change —
 * e.g. an old slider value left under a field that is now a radio, or an
 * option label that was reworded — which would otherwise count as answered.
 */
export function isFieldAnswered(field: Field, value: unknown, allAnswers?: IntakeAnswers, sectionId = ''): boolean {
  if (repeaterNotApplicable(field, allAnswers, sectionId)) return true;
  if (field.type === 'number' && field.ageConfig) {
    const date = allAnswers?.[field.answerSection ?? sectionId]?.[field.ageConfig?.date_field ?? ''];
    if (date !== undefined && date !== null && date !== '') return ageFromBirthdate(date) !== undefined;
    return (typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value))) && Number.isInteger(Number(value)) && Number(value) >= 0;
  }
  if (field.type === 'photo') return photoAnswered(field, value, allAnswers, sectionId);
  if (field.type === 'month_year') return dateAnswered(value, field.dateConfig);
  if (field.type === 'number' && field.options?.length) {
    return field.options.includes(String(value)) || (value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value)) && Number(value) >= 0);
  }
  if (isEmpty(value)) return false;
  // A multi-box text field (`lines`) stores a string array; it counts as
  // answered only when at least one of the boxes holds real text.
  if (field.type === 'text') {
    if (!field.lines || !Array.isArray(value)) return typeof value === 'string' && value.trim() !== '';
    return value.some((v) => typeof v === 'string' && v.trim() !== '');
  }
  if (field.type === 'radio') {
    return (field.options ?? []).includes(String(value));
  }
  if (field.type === 'multi') {
    if (!Array.isArray(value)) return false;
    const opts = field.options ?? [];
    return value.some((v) => opts.includes(String(v)));
  }
  if (field.type === 'repeater') {
    // An added-but-blank row (e.g. `[{}]`) is non-empty as an array but holds
    // no real answer — require at least one row with a non-empty sub-value.
    if (!Array.isArray(value)) return false;
    const dateSubs = (field.sub ?? []).filter(sub => sub.type === 'month_year');
    for (const row of value) {
      if (!row || typeof row !== 'object') continue;
      for (const sub of dateSubs) {
        const v = row[sub.id];
        if (v !== undefined && v !== null && v !== '' && !dateAnswered(v, sub.dateConfig)) return false;
      }
    }
    return value.some(
      (row) =>
        row != null &&
        typeof row === 'object' &&
        Object.entries(row as Record<string, unknown>).some(
          ([key, v]) => !key.startsWith('_') && ((typeof v === 'string' && v.trim() !== '') || (dateSubs.some(sub => sub.id === key) && dateAnswered(v, dateSubs.find(sub => sub.id === key)?.dateConfig))),
        ),
    );
  }
  return true;
}

export const repeaterNaKey = (field: Field) => `na:${field.id}`;
export function repeaterNotApplicable(field: Field, answers: IntakeAnswers = {}, sectionId = ''): boolean {
  return field.type === 'repeater' && !!field.options?.length
    && answers[field.answerSection ?? sectionId]?.[repeaterNaKey(field)] === 'yes';
}

/** Photo metadata stays tied to the attachment, including after a section moves. */
export const photoId = (reference: string) => reference.split(':')[1] ?? '';
export const photoReferences = (value: unknown): string[] => Array.isArray(value)
  ? value.filter((v): v is string => typeof v === 'string' && /^attachment:[^:]+:/.test(v)) : [];
export const photoNaKey = (field: Field, sectionId: string) => field.photoConfig?.na_key ?? `na:${field.answerSection ?? sectionId}:${field.id}`;
export function photoNotApplicable(field: Field, answers: IntakeAnswers = {}, sectionId = '') {
  return !!field.photoConfig?.allow_na && answers.__photos?.[photoNaKey(field, sectionId)] === 'yes';
}
/** A configured slot can retain a pre-existing answer/attachment namespace. */
export function photoSlotReferences(field: Field, slot: NonNullable<NonNullable<Field['photoConfig']>['slots']>[number], value: unknown, answers: IntakeAnswers = {}, sectionId = '') {
  if (slot.answer_field) {
    const refs = photoReferences(slot.answer_field === field.id ? value : answers[field.answerSection ?? sectionId]?.[slot.answer_field]);
    return slot.answer_slot ? refs.filter(ref => answers.__photos?.[`slot-${photoId(ref)}`] === slot.answer_slot) : refs;
  }
  return photoReferences(value).filter(ref => answers.__photos?.[`slot-${photoId(ref)}`] === slot.id);
}

/** Present the four stored hoof answers as one question while retaining their original upload keys. */
export function groupedPhotoFields(fields: Field[]): Field[] {
  const heading = fields.find(field => field.id === 'sec-hoef-upload');
  if (!heading || heading.type !== 'sectionhead') return fields;
  const hoofIds = ['foto-hoef-lv', 'foto-hoef-rv', 'foto-hoef-la', 'foto-hoef-ra'];
  const hooves = hoofIds.map(id => fields.find(field => field.id === id));
  if (hooves.some(field => !field?.photoConfig?.slots?.length)) return fields;
  const groups = hooves.map(field => ({ field: field!.id, label: field!.label,
    slots: field!.photoConfig!.slots!.map(slot => ({ id: slot.id, label: slot.label })) }));
  const grouped: Field = { ...heading, type: 'photo', optional: false,
    photoConfig: { ...heading.photoConfig, allow_na: true, na_key: 'extra-hoeven-na', legacy_field: 'foto-hoef-instructie', grouped_hooves: groups,
      slots: groups.flatMap(group => group.slots.map(slot => ({ id: `${group.field}-${slot.id}`,
        label: `${group.label} — ${slot.label}`, answer_field: group.field, answer_slot: slot.id }))) } };
  return fields.filter(field => !hoofIds.includes(field.id) && field.id !== 'foto-hoef-instructie')
    .map(field => field.id === heading.id ? grouped : field);
}
export function photoFieldReferences(field: Field, value: unknown, answers: IntakeAnswers = {}, sectionId = '') {
  return Array.from(new Set([...photoReferences(value), ...(field.photoConfig?.slots ?? []).flatMap(slot => photoSlotReferences(field, slot, value, answers, sectionId))]));
}
export function photoAnswered(field: Field, value: unknown, answers: IntakeAnswers = {}, sectionId = '') {
  if (photoNotApplicable(field, answers, sectionId)) return true;
  const refs = photoFieldReferences(field, value, answers, sectionId), config = field.photoConfig;
  if (!refs.length) return false;
  if (config?.year_required && refs.some(ref => !validPhotoYear(answers.__photos?.[`year-${photoId(ref)}`]))) return false;
  return !config?.slots?.length || config.slots.every(slot => photoSlotReferences(field, slot, value, answers, sectionId).length > 0);
}
export function validPhotoYear(value: unknown): boolean {
  return typeof value === 'string' && /^\d{4}$/.test(value) && Number(value) >= 1000 && Number(value) <= new Date().getFullYear();
}
export function needsAnswer(field: Field, value: unknown): boolean {
  // Historical images are optional, but each supplied image needs its year.
  return isFieldRequired(field) || !!(field.type === 'photo' && field.photoConfig?.year_required && photoReferences(value).length);
}

/** Count of selected options that are not a "none / not-applicable" sentinel. */
function checkedCount(value: unknown, noneOptions: readonly string[]): number {
  if (Array.isArray(value)) return value.filter((v) => !isNoneOption(String(v), noneOptions)).length;
  if (value == null || value === '' || isNoneOption(String(value), noneOptions)) return 0;
  return 1;
}

/**
 * `showIf` evaluator. Returns true when every key matches.
 *
 * A `showIf` key normally references another field in the SAME section. A key
 * containing a dot (`"section.field"`) instead references a field in another
 * section — used for blocks that depend on `paard.geslacht` (the gender-only
 * fertility / penis-koker questions in the geschiedenis section). Cross-section
 * lookups require `allAnswers`; without it they evaluate as "not matching" so
 * the dependent field stays hidden rather than rendering for the wrong horse.
 */
export function showField(
  field: Field,
  sectionAnswers: SectionAnswers,
  allAnswers?: IntakeAnswers,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): boolean {
  if (field.photoConfig?.existing_only && !photoReferences(sectionAnswers[field.id]).length) return false;
  if (!field.showIf) return true;
  for (const [k, trigger] of Object.entries(field.showIf)) {
    if (k === '$all' || k === '$any') {
      const results = (trigger as NonNullable<Field['showIf']>[]).map(showIf => showField({ ...field, photoConfig: undefined, showIf }, sectionAnswers, allAnswers, noneOptions));
      if (k === '$all' ? !results.every(Boolean) : !results.some(Boolean)) return false;
      continue;
    }
    if (k === '$not') {
      if (showField({ ...field, photoConfig: undefined, showIf: trigger as Field['showIf'] }, sectionAnswers, allAnswers, noneOptions)) return false;
      continue;
    }
    const value = k.includes('.')
      ? (() => {
          const [sid, fid] = k.split('.');
          return allAnswers?.[sid]?.[fid];
        })()
      : sectionAnswers[k];
    if (trigger && typeof trigger === 'object' && !Array.isArray(trigger) && 'gte' in trigger) {
      if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) || Number(value) < Number(trigger.gte)) return false;
      continue;
    }
    const set = (Array.isArray(trigger) ? trigger : [trigger]) as string[];
    // Special marker: show when the referenced multi field has at least one
    // non-"geen" option selected (used for "describe what you picked" follow-ups).
    if (set.includes('any-checked')) {
      if (checkedCount(value, noneOptions) < 1) return false;
      continue;
    }
    // Special marker: show when the referenced multi field has two or more
    // non-"geen" options selected (used for the water "licht toe hoe dit
    // verdeeld is" follow-ups, which only make sense with multiple picks).
    if (set.includes('multi-checked')) {
      if (checkedCount(value, noneOptions) < 2) return false;
      continue;
    }
    if (!matches(value, trigger as Trigger)) return false;
  }
  return true;
}

/** Visible (non-sectionhead) fields for a section, after applying showIf. */
export function visibleFields(
  section: Section,
  answers: SectionAnswers,
  allAnswers?: IntakeAnswers,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): Field[] {
  if (allAnswers) answers = answersForSection(section, allAnswers);
  return groupedPhotoFields(section.fields)
    .filter((f) => f.type !== 'sectionhead')
    .filter((f) => showField(f, answers, allAnswers, noneOptions));
}

/** All schema-visible fields (including sectionheads) after showIf. */
export function visibleFieldsForRender(
  section: Section,
  answers: SectionAnswers,
  allAnswers?: IntakeAnswers,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): Field[] {
  if (allAnswers) answers = answersForSection(section, allAnswers);
  return groupedPhotoFields(section.fields).filter((f) => showField(f, answers, allAnswers, noneOptions));
}

/**
 * Visible fields that still need an answer. Per the deel-2 spec every visible
 * question is mandatory, so this is "all visible non-optional fields that are
 * not yet answered" — not just the ones tagged `required`.
 */
export function missingRequired(
  section: Section,
  answers: SectionAnswers,
  allAnswers?: IntakeAnswers,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): Field[] {
  if (allAnswers) answers = answersForSection(section, allAnswers);
  return visibleFields(section, answers, allAnswers, noneOptions).filter(
    (f) => needsAnswer(f, answers[f.id])
      && !isFieldAnswered(f, answers[f.id], allAnswers ?? { [f.answerSection ?? section.id]: answers }, section.id),
  );
}

/** Count of visible (non-sectionhead) fields that are genuinely answered. */
export function answeredCount(
  section: Section,
  answers: SectionAnswers,
  allAnswers?: IntakeAnswers,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): {
  answered: number;
  total: number;
} {
  if (allAnswers) answers = answersForSection(section, allAnswers);
  const renderable = visibleFields(section, answers, allAnswers, noneOptions);
  const answered = renderable.filter((f) => isFieldAnswered(f, answers[f.id], allAnswers ?? { [f.answerSection ?? section.id]: answers }, section.id)).length;
  return { answered, total: renderable.length };
}

/** Whether the section has all required fields answered. */
export function isSectionComplete(
  section: Section,
  answers: SectionAnswers,
  allAnswers?: IntakeAnswers,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): boolean {
  return missingRequired(section, answers, allAnswers, noneOptions).length === 0;
}

/** Lifecycle status used to render the section list. */
export type SectionStatus = 'done' | 'active' | 'todo';

export function sectionStatus(
  section: Section,
  answers: SectionAnswers,
  isFirstUnfinished: boolean,
  allAnswers?: IntakeAnswers,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): SectionStatus {
  if (isSectionComplete(section, answers, allAnswers, noneOptions)) {
    return 'done';
  }
  return isFirstUnfinished ? 'active' : 'todo';
}

/** Overall progress 0..100 across all sections. */
export function intakeProgress(
  answers: IntakeAnswers,
  schema: Section[] = INTAKE_SCHEMA,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): {
  done: number;
  total: number;
  pct: number;
} {
  const total = schema.length;
  let done = 0;
  for (const sec of schema) {
    const a = answersForSection(sec, answers);
    if (isSectionComplete(sec, a, answers, noneOptions)) done++;
  }
  return { done, total, pct: total ? Math.round((done / total) * 100) : 0 };
}

/** Returns the id of the section the customer should land on. */
export function nextSectionId(
  answers: IntakeAnswers,
  schema: Section[] = INTAKE_SCHEMA,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): string {
  for (const sec of schema) {
    const a = answersForSection(sec, answers);
    if (!isSectionComplete(sec, a, answers, noneOptions)) return sec.id;
  }
  return schema[schema.length - 1]?.id ?? '';
}

/** Whether the answer triggers a `flagIf` on the field. */
export function fieldFlagged(
  field: Field,
  value: unknown,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): boolean {
  if (!field.flagIf) return false;
  if (field.flagIf === 'non-empty') return !isEmpty(value);
  if (field.flagIf === 'any') {
    if (Array.isArray(value)) return value.filter((v) => !isNoneOption(String(v), noneOptions)).length > 0;
    return !isEmpty(value) && !isNoneOption(String(value), noneOptions);
  }
  return matches(value, field.flagIf);
}

/** Whether the answer triggers a `criticalIf` block. */
export function fieldCritical(field: Field, value: unknown): boolean {
  if (!field.criticalIf) return false;
  return matches(value, field.criticalIf);
}

/** Whether the entire intake is blocked from auto-start because of a critical answer. */
export function hasCriticalAnswers(answers: IntakeAnswers, schema: Section[] = INTAKE_SCHEMA): boolean {
  for (const sec of schema) {
    const a = answersForSection(sec, answers);
    for (const f of sec.fields) {
      if (fieldCritical(f, a[f.id])) return true;
    }
  }
  return false;
}

/** Sum of all flagged answers — used for the submit-screen tally. */
export function countFlags(
  answers: IntakeAnswers,
  schema: Section[] = INTAKE_SCHEMA,
  noneOptions: readonly string[] = INTAKE_NONE_OPTIONS,
): number {
  let n = 0;
  for (const sec of schema) {
    const a = answersForSection(sec, answers);
    for (const f of visibleFields(sec, a, answers, noneOptions)) {
      if (!repeaterNotApplicable(f, answers, sec.id) && fieldFlagged(f, a[f.id], noneOptions)) n++;
    }
  }
  return n;
}

/** Reminder thresholds count applicable mandatory questions, never rounded section percentages. */
export function requiredProgress(answers: IntakeAnswers, schema: Section[] = INTAKE_SCHEMA, noneOptions = INTAKE_NONE_OPTIONS) {
  const required = schema.flatMap(section => visibleFields(section, answersForSection(section, answers), answers, noneOptions)
    .filter(field => needsAnswer(field, answersForSection(section, answers)[field.id])).map(field => ({ section, field })));
  const missing = required.filter(({ section, field }) => !isFieldAnswered(field, answersForSection(section, answers)[field.id], answers, section.id));
  return { total: required.length, answered: required.length - missing.length, missing,
    stage: required.length > 0 && missing.length === 0 ? 'ready' : required.length > 0 && (required.length - missing.length) / required.length >= .9 ? 'almost' : 'continue' };
}

export function isPhotoSection(section: Section): boolean {
  const questions = section.fields.filter(field => field.type !== 'sectionhead');
  return questions.length > 0 && questions.every(field => field.type === 'photo');
}

/** Read-only compatibility projection; original saved values remain intact. */
export function normalizeAnswer(field: Field, value: FieldValue): FieldValue {
  const alias = (v: unknown) => typeof v === 'string' ? field.answerAliases?.[v] ?? v : v;
  return (Array.isArray(value) ? value.map(alias) : alias(value)) as FieldValue;
}
