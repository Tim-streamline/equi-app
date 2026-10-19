import type { IntakeAnswers } from './schema';

export async function preserveIntakeForRefresh(options: {
  drain: () => Promise<unknown>;
  checkSession: () => void;
  answersToSave: () => IntakeAnswers | null;
  ensureBooking: () => Promise<string>;
  save: (id: string, section: string, field: string, value: unknown) => Promise<unknown>;
}) {
  await options.drain();
  options.checkSession();
  const answers = options.answersToSave();
  if (!answers) return;
  const id = await options.ensureBooking();
  options.checkSession();
  // Re-save the latest in-memory answers, including any earlier failed local save.
  for (const [section, values] of Object.entries(answers)) for (const [field, value] of Object.entries(values)) {
    options.checkSession();
    await options.save(id, section, field, value);
  }
  options.checkSession();
}
