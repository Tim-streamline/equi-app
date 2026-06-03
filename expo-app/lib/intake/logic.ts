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

/**
 * Whether a multi-option label is a "none / not-applicable" sentinel — these
 * are mutually exclusive with every other option and never count as a real
 * flag/"any-checked" answer. Generalized from the original literal `'geen'`
 * to also cover "Geen van onderstaande", "Geen andere diersoorten",
 * "Nee, nooit", etc. that the deel-2 spec introduced.
 */
const NONE_OPTIONS = new Set([
  'geen',
  'nee',
  'nee, nooit',
  'niet van toepassing',
  'geen van onderstaande',
  'geen andere diersoorten',
  'geen belangrijke veranderingen',
  'geen echte schuilmogelijkheid',
  'geen opvallende bijzonderheden',
  'geen bijzonderheden',
]);

export function isNoneOption(option: string): boolean {
  // Explicit allowlist rather than a `startsWith('geen')` rule: several
  // legitimate, combinable traits also start with "Geen" (e.g. "Geen actief
  // beheer", "Geen duidelijke klachten, maar…") and must NOT clear the rest.
  return NONE_OPTIONS.has(option.trim().toLowerCase());
}

/** Whether the field/value indicates "every question is mandatory" gating. */
export function isFieldRequired(field: Field): boolean {
  if (field.optional) return false;
  if (field.type === 'sectionhead') return false;
  return true;
}

/**
 * Whether a field counts as genuinely answered. Stricter than `!isEmpty` for
 * option-based fields: a value is only valid if it still matches the field's
 * current `options`. This keeps the "X / Y vragen beantwoord" tally (and the
 * completion check) honest when persisted answers predate a schema change —
 * e.g. an old slider value left under a field that is now a radio, or an
 * option label that was reworded — which would otherwise count as answered.
 */
export function isFieldAnswered(field: Field, value: unknown): boolean {
  if (isEmpty(value)) return false;
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
    return value.some(
      (row) =>
        row != null &&
        typeof row === 'object' &&
        Object.values(row as Record<string, unknown>).some(
          (v) => typeof v === 'string' && v.trim() !== '',
        ),
    );
  }
  return true;
}

/** `showIf` evaluator. Returns true when every key matches. */
export function showField(field: Field, sectionAnswers: SectionAnswers): boolean {
  if (!field.showIf) return true;
  for (const [k, trigger] of Object.entries(field.showIf)) {
    const value = sectionAnswers[k];
    const set = Array.isArray(trigger) ? trigger : [trigger];
    // Special marker: show when the referenced multi field has at least one
    // non-"geen" option selected (used for "describe what you picked" follow-ups).
    if (set.includes('any-checked')) {
      const checked = Array.isArray(value)
        ? value.some((v) => !isNoneOption(String(v)))
        : value != null && value !== '' && !isNoneOption(String(value));
      if (!checked) return false;
      continue;
    }
    if (!matches(value, trigger)) return false;
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

/**
 * Visible fields that still need an answer. Per the deel-2 spec every visible
 * question is mandatory, so this is "all visible non-optional fields that are
 * not yet answered" — not just the ones tagged `required`.
 */
export function missingRequired(section: Section, answers: SectionAnswers): Field[] {
  return visibleFields(section, answers).filter(
    (f) => isFieldRequired(f) && !isFieldAnswered(f, answers[f.id]),
  );
}

/** Count of visible (non-sectionhead) fields that are genuinely answered. */
export function answeredCount(section: Section, answers: SectionAnswers): {
  answered: number;
  total: number;
} {
  const renderable = visibleFields(section, answers);
  const answered = renderable.filter((f) => isFieldAnswered(f, answers[f.id])).length;
  return { answered, total: renderable.length };
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
    if (Array.isArray(value)) return value.filter((v) => !isNoneOption(String(v))).length > 0;
    return !isEmpty(value) && !isNoneOption(String(value));
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
