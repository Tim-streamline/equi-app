// Pure evaluators over the intake schema + an answer set. Used to drive
// conditional rendering (showIf), aggregate the therapist's review chips
// (flagIf / criticalIf), and decide when a section is "done".

import {
  Field,
  IntakeAnswers,
  INTAKE_SCHEMA,
  Section,
  SectionAnswers,
  Trigger,
} from './schema';

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

/** `showIf` evaluator. Returns true when every key matches. */
export function showField(field: Field, sectionAnswers: SectionAnswers): boolean {
  if (!field.showIf) return true;
  for (const [k, trigger] of Object.entries(field.showIf)) {
    if (!matches(sectionAnswers[k], trigger)) return false;
  }
  return true;
}

/** Visible (non-sectionhead) fields for a section, after applying showIf. */
export function visibleFields(section: Section, answers: SectionAnswers): Field[] {
  return section.fields
    .filter((f) => f.type !== 'sectionhead')
    .filter((f) => showField(f, answers));
}

/** All schema-visible fields (including sectionheads) after showIf. */
export function visibleFieldsForRender(section: Section, answers: SectionAnswers): Field[] {
  return section.fields.filter((f) => showField(f, answers));
}

/** Required fields whose answer is still missing. */
export function missingRequired(section: Section, answers: SectionAnswers): Field[] {
  return visibleFields(section, answers).filter(
    (f) => f.required && isEmpty(answers[f.id]),
  );
}

/** Whether the section has all required fields answered. */
export function isSectionComplete(section: Section, answers: SectionAnswers): boolean {
  return missingRequired(section, answers).length === 0;
}

/** Lifecycle status used to render the section list. */
export type SectionStatus = 'done' | 'active' | 'todo';

export function sectionStatus(
  section: Section,
  answers: SectionAnswers,
  isFirstUnfinished: boolean,
): SectionStatus {
  if (isSectionComplete(section, answers) && Object.keys(answers).length > 0) {
    return 'done';
  }
  return isFirstUnfinished ? 'active' : 'todo';
}

/** Overall progress 0..100 across all sections. */
export function intakeProgress(answers: IntakeAnswers): {
  done: number;
  total: number;
  pct: number;
} {
  const total = INTAKE_SCHEMA.length;
  let done = 0;
  for (const sec of INTAKE_SCHEMA) {
    const a = answers[sec.id] ?? {};
    if (isSectionComplete(sec, a) && Object.keys(a).length > 0) done++;
  }
  return { done, total, pct: total ? Math.round((done / total) * 100) : 0 };
}

/** Returns the id of the section the customer should land on. */
export function nextSectionId(answers: IntakeAnswers): string {
  for (const sec of INTAKE_SCHEMA) {
    const a = answers[sec.id] ?? {};
    if (!isSectionComplete(sec, a) || Object.keys(a).length === 0) return sec.id;
  }
  return INTAKE_SCHEMA[INTAKE_SCHEMA.length - 1].id;
}

/** Whether the answer triggers a `flagIf` on the field. */
export function fieldFlagged(field: Field, value: unknown): boolean {
  if (!field.flagIf) return false;
  if (field.flagIf === 'non-empty') return !isEmpty(value);
  if (field.flagIf === 'any') {
    if (Array.isArray(value)) return value.filter((v) => v !== 'geen').length > 0;
    return !isEmpty(value) && value !== 'geen';
  }
  return matches(value, field.flagIf);
}

/** Whether the answer triggers a `criticalIf` block. */
export function fieldCritical(field: Field, value: unknown): boolean {
  if (!field.criticalIf) return false;
  return matches(value, field.criticalIf);
}

/** Whether the entire intake is blocked from auto-start because of a critical answer. */
export function hasCriticalAnswers(answers: IntakeAnswers): boolean {
  for (const sec of INTAKE_SCHEMA) {
    const a = answers[sec.id] ?? {};
    for (const f of sec.fields) {
      if (fieldCritical(f, a[f.id])) return true;
    }
  }
  return false;
}

/** Sum of all flagged answers — used for the submit-screen tally. */
export function countFlags(answers: IntakeAnswers): number {
  let n = 0;
  for (const sec of INTAKE_SCHEMA) {
    const a = answers[sec.id] ?? {};
    for (const f of sec.fields) {
      if (fieldFlagged(f, a[f.id])) n++;
    }
  }
  return n;
}
