export const ACTIVE_PROTOCOL_SQL = `
  SELECT *
  FROM protocols
  WHERE horse_id = ?
    AND status = 'active'
    AND published_at IS NOT NULL
  ORDER BY published_at DESC
  LIMIT 1
`;

type Row = { id: string; [key: string]: unknown };

export type ProtocolPlanSupplement = Row & {
  protocolPhaseId: string;
  phaseTitle: string;
  phaseOrder: number;
  weeks: Row[];
  phaseWeekNumbers: number[];
  protocolWeekNumbers: number[];
};

export type ProtocolPlanPhase = Row & {
  order: number;
  weeks: Row[];
  supplements: ProtocolPlanSupplement[];
};

type ProtocolPlanInput = {
  phases: Row[];
  weeks: Row[];
  supplements: Row[];
  supplementWeeks: Row[];
};

function numeric(value: unknown, fallback = 0): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function byNumber(a: Row, b: Row): number {
  return numeric(a.number) - numeric(b.number);
}

export function buildProtocolPlan({
  phases,
  weeks,
  supplements,
  supplementWeeks,
}: ProtocolPlanInput): ProtocolPlanPhase[] {
  const weekById = new Map(weeks.map((week) => [week.id, week]));
  const linkedWeeksBySupplement = new Map<string, Row[]>();

  for (const link of supplementWeeks) {
    const supplementId = String(link.protocolPhaseSupplementId ?? '');
    const week = weekById.get(String(link.protocolPhaseWeekId ?? ''));
    if (!supplementId || !week) continue;
    const linked = linkedWeeksBySupplement.get(supplementId) ?? [];
    linked.push(week);
    linkedWeeksBySupplement.set(supplementId, linked);
  }

  return [...phases]
    .sort((a, b) => numeric(a.order) - numeric(b.order))
    .map((phase) => {
      const phaseWeeks = weeks
        .filter((week) => week.protocolPhaseId === phase.id)
        .sort(byNumber);
      const phaseSupplements = supplements
        .filter((supplement) => supplement.protocolPhaseId === phase.id)
        .sort((a, b) => String(a.name ?? '').localeCompare(String(b.name ?? ''), 'nl'))
        .map((supplement): ProtocolPlanSupplement => {
          const scheduledWeeks = [...(linkedWeeksBySupplement.get(supplement.id) ?? [])].sort(byNumber);
          return {
            ...supplement,
            protocolPhaseId: phase.id,
            phaseTitle: String(phase.title ?? ''),
            phaseOrder: numeric(phase.order),
            weeks: scheduledWeeks,
            phaseWeekNumbers: scheduledWeeks.map((week) => numeric(week.number)),
            protocolWeekNumbers: scheduledWeeks.map((week) => numeric(week.protocolWeekNumber)),
          };
        });

      return {
        ...phase,
        order: numeric(phase.order),
        weeks: phaseWeeks,
        supplements: phaseSupplements,
      };
    });
}

export function supplementsForProtocolWeek(
  plan: ProtocolPlanPhase[],
  protocolWeek: number,
): ProtocolPlanSupplement[] {
  return plan.flatMap((phase) =>
    phase.supplements.filter((supplement) => supplement.protocolWeekNumbers.includes(protocolWeek)),
  );
}

export type ProtocolSupplementRow = {
  id: string;
  title: string;
  dosage: string | null;
};

export type ProtocolSupplementIntakeRow = Row & {
  protocolPhaseSupplementId: string;
  dosage?: string | null;
  done?: number | boolean;
  takenAt?: string | null;
};

export type ProtocolSupplementDayRow = ProtocolSupplementRow & {
  done: boolean;
  intakeId: string | null;
};

/** Minimal display contract for the supplement list on the Today tab. */
export function protocolSupplementRowsForWeek(
  plan: ProtocolPlanPhase[],
  protocolWeek: number,
): ProtocolSupplementRow[] {
  return supplementsForProtocolWeek(plan, protocolWeek).map((supplement) => ({
    id: supplement.id,
    title: String(supplement.name ?? 'Supplement'),
    dosage: supplement.dosage ? String(supplement.dosage) : null,
  }));
}

/** Combines the active supplement plan with intake records for one calendar day. */
export function protocolSupplementRowsForDay(
  plan: ProtocolPlanPhase[],
  protocolWeek: number,
  intakes: ProtocolSupplementIntakeRow[],
): ProtocolSupplementDayRow[] {
  const intakeBySupplement = new Map(
    intakes.map((intake) => [intake.protocolPhaseSupplementId, intake]),
  );

  return protocolSupplementRowsForWeek(plan, protocolWeek).map((supplement) => {
    const intake = intakeBySupplement.get(supplement.id);
    return {
      ...supplement,
      done: !!intake?.done,
      intakeId: intake?.id ?? null,
    };
  });
}

export type ProtocolOrderItem = {
  id: string;
  name: string;
  dosage: string | null;
  phaseTitles: string[];
};

export function protocolOrderItems(plan: ProtocolPlanPhase[]): ProtocolOrderItem[] {
  const items = new Map<string, ProtocolOrderItem>();

  for (const phase of plan) {
    for (const supplement of phase.supplements) {
      const name = String(supplement.name ?? 'Supplement');
      const dosage = supplement.dosage ? String(supplement.dosage) : null;
      const key = `${String(supplement.supplementId ?? name)}|${dosage ?? ''}`;
      const existing = items.get(key);
      if (existing) {
        if (!existing.phaseTitles.includes(supplement.phaseTitle)) {
          existing.phaseTitles.push(supplement.phaseTitle);
        }
        continue;
      }
      items.set(key, {
        id: supplement.id,
        name,
        dosage,
        phaseTitles: [supplement.phaseTitle],
      });
    }
  }

  return [...items.values()];
}

export function formatProtocolWeeks(weeks: number[]): string {
  const unique = [...new Set(weeks)].filter((week) => week > 0).sort((a, b) => a - b);
  if (unique.length === 0) return 'Geen weken geselecteerd';

  const ranges: string[] = [];
  let start = unique[0];
  let end = unique[0];

  for (const week of unique.slice(1)) {
    if (week === end + 1) {
      end = week;
      continue;
    }
    ranges.push(start === end ? `${start}` : `${start}–${end}`);
    start = week;
    end = week;
  }
  ranges.push(start === end ? `${start}` : `${start}–${end}`);

  return `Week ${ranges.join(', ')}`;
}
