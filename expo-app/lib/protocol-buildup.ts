import type { DashboardSupplement } from './horse-dashboard';

const doseKey = (value: string | null) => {
  const text = value?.trim() ?? '';
  const match = text.match(/^(\d+(?:[.,]\d+)?)\s+(.+)$/);
  return match ? `${Number(match[1].replace(',', '.'))} ${match[2].toLowerCase()}` : text;
};

/** Local saved doses remain authoritative while offline writes are syncing. */
export function buildupState(item: DashboardSupplement, date: string) {
  const info = item.buildup;
  if (!info || info.date !== date || !item.dosage) return null;
  const matches = doseKey(item.dosage) === doseKey(info.currentDosage);
  return { ...info, currentDosage: item.dosage, currentStep: matches ? info.currentStep : null,
    next: matches ? info.next : null,
    building: doseKey(item.dosage) !== doseKey(info.fullDosage),
    changed: !!info.previousDosage && doseKey(info.previousDosage) !== doseKey(item.dosage) };
}

export function buildupSchedule(item: DashboardSupplement, date?: string) {
  const current = date ? buildupState(item, date) : null;
  const groups: { from: number; to: number; dosage: string | null; current: boolean }[] = [];
  for (const step of item.buildup?.schedule ?? []) {
    const last = groups[groups.length - 1];
    const selected = current?.currentStep === step.day;
    if (last && last.dosage === step.dosage && last.to === step.day - 1) {
      last.to = step.day; last.current ||= selected;
    } else groups.push({ from: step.day, to: step.day, dosage: step.dosage, current: selected });
  }
  return groups;
}

export function dailyDoseChanges(items: DashboardSupplement[], date: string) {
  const unique = new Map<string, DashboardSupplement>();
  for (const item of items) if (buildupState(item, date)?.changed && !unique.has(item.id)) unique.set(item.id, item);
  return [...unique.values()];
}
