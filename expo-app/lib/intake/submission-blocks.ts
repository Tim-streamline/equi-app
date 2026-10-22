import type { IntakeAnswers, Section } from './schema';
import { answersForSection, visibleFields } from './logic';

export type SubmissionBlock = { section: string; field: string; label: string; answer: string; message: string };

export function submissionBlocks(schema: Section[], answers: IntakeAnswers, noneOptions: readonly string[]): SubmissionBlock[] {
  return schema.flatMap(section => {
    const values = answersForSection(section, answers);
    return visibleFields(section, values, answers, noneOptions).flatMap(field => {
      const selected = values[field.id];
      const choices: unknown[] = Array.isArray(selected) ? selected : [selected];
      return (field.submissionBlocks ?? []).filter(rule => choices.includes(rule.answer)
        && Object.entries(rule.when ?? {}).every(([key, accepted]) => accepted.includes(values[key] as string)))
        .map(rule => ({ section: section.id, field: field.id, label: field.label, answer: rule.answer, message: rule.message }));
    });
  });
}
