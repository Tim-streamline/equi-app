export type ProtocolEvaluationData = {
  available: boolean; protocolId: string; horseId: string; horseName: string;
  endsOn: string; daysRemaining: number; discountUntil: string; discountEligible: boolean; completed?: boolean;
  questions: Record<string, string>; questionVersion: string; revision: number; answers: Record<string, string>; submittedAt: string | null;
  basePriceCents: number; priceCents: number; discountPercent: number; durationMonths: number;
};

export const evaluationMoney = (cents: number) => new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(cents / 100);
export const evaluationDate = (date: string) => new Date(`${date}T12:00:00`).toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' });
export const horsePossessive = (name: string) => `${name}'${/s$/i.test(name) ? '' : 's'}`;

export function evaluationComplete(questions: Record<string, string>, answers: Record<string, string>) {
  return Object.keys(questions).length > 0 && Object.keys(questions).every(key => !!answers[key]?.trim());
}

/** Keep local edits and bring in answers edited elsewhere since the form was opened. */
export function reconcileEvaluation(base: Record<string, string>, local: Record<string, string>, saved: Record<string, string>) {
  const answers = { ...saved };
  const conflicts: Record<string, string> = {};
  for (const key of Object.keys(local)) {
    if ((local[key] ?? '') === (base[key] ?? '')) continue;
    if ((saved[key] ?? '') !== (base[key] ?? '') && (saved[key] ?? '') !== local[key]) conflicts[key] = saved[key] ?? '';
    answers[key] = local[key];
  }
  return { answers, conflicts };
}
