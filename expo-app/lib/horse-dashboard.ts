export type LibraryRecommendation = {
  id: string;
  title: string;
  format: string;
  description?: string;
  durationLabel?: string;
  heroImageUrl?: string | null;
  creditCost: number;
  isPlus?: boolean;
  unlocked: boolean;
  phaseContext?: string;
};
export type DashboardSupplement = {
  id: string;
  name: string;
  dosage: string | null;
  description?: string;
  instructions?: string;
  frequencyLabel?: string;
  phaseTitle: string;
  done?: boolean;
};
export type DashboardPhase = {
  id: string;
  title: string;
  description?: string;
  weekLabel: string;
  durationWeeks: number;
  durationLabel: string;
  currentPhaseWeek: number | null;
  state: "active" | "done" | "preview" | "locked";
  accessible: boolean;
  startsAt: string | null;
  endsAt: string | null;
  weekStart: number;
  weekEnd: number;
  contentAvailable: boolean;
  availableAt: string | null;
  statusLabel: string;
  supplements: DashboardSupplement[];
};
export type DashboardNotification = {
  id: string;
  type: "weekly_update" | "next_phase";
  title: string;
  body: string;
  items: DashboardSupplement[];
  orderItems?: DashboardSupplement[];
  phaseId?: string;
};
export type DashboardAdvice = {
  id: string;
  title: string;
  description?: string;
  action: "do" | "avoid";
  note?: string;
  frequency?: string;
  url?: string;
  ctaLabel?: string;
};
export type DashboardProtocol = {
  id: string;
  title: string;
  currentDay: number;
  currentWeek: number;
  totalWeeks: number;
  progressPercent: number;
  statusLabel: string;
  todayLabel: string;
  phaseLabel: string;
  phases: DashboardPhase[];
  orderItems: DashboardSupplement[];
  notifications: DashboardNotification[];
  today: {
    items: DashboardSupplement[];
    total: number;
    done: number;
    percentage: number;
  };
  calendar: {
    month: string;
    label: string;
    previousMonth: string;
    nextMonth: string;
    cells: ({
      date: string;
      day: number;
      state: "default" | "complete" | "partial" | "missed";
      isToday: boolean;
      available?: boolean;
    } | null)[];
  };
  nutrition: {
    roughage: {
      rangeLabel: string | null;
      description: string;
      sugar: string;
      protein: string;
    };
    hayLibraryItem: LibraryRecommendation | null;
    waterLibraryItem: LibraryRecommendation | null;
    feeds: {
      id: string;
      name: string;
      dosage?: string;
      status: "continue" | "stop";
      note?: string;
    }[];
    water: { types: string[]; needsAnalysis: boolean; advice?: string };
  };
  management: { id: string; title: string; items: DashboardAdvice[] }[];
  movement: { id: string; title: string; description?: string }[];
  analysis: {
    summary: string;
    priorities: { id: string; title: string; body: string }[];
    observations: string[];
  } | null;
};
export type HorseDashboard = {
  generatedAt: string;
  timezone?: string;
  date: string;
  greeting: string;
  horse: { id: string; name: string };
  hasPlus: boolean;
  showPlusUpsell: boolean;
  variant: "basic" | "plus";
  credits: number;
  plusOffer: {
    name: string;
    description: string | null;
    priceLabel: string;
    priceSuffix: string | null;
    benefits: string[];
  } | null;
  seasonalTip: {
    id: string;
    intro?: string;
    month: string;
    title?: string;
    body: string;
    item: LibraryRecommendation | null;
  } | null;
  recommendations: LibraryRecommendation[];
  protocol: DashboardProtocol | null;
};

// A response/cache from a previous selection must never appear under the new horse.
export function dashboardForHorse(
  data: HorseDashboard | null,
  horseId: string,
): HorseDashboard | null {
  return data?.horse.id === horseId ? data : null;
}

export function libraryPath(
  item: Pick<LibraryRecommendation, "id" | "format">,
) {
  return {
    pathname:
      item.format === "video"
        ? "/(tabs)/library/video/[id]"
        : "/(tabs)/library/article/[id]",
    params: { id: item.id },
  };
}

/** Recalculate cached phase labels on midnight/resume without exposing unavailable content. */
export function dashboardAtTime(data: HorseDashboard, now: Date): HorseDashboard {
  if (!data.protocol) return data;
  const phases = data.protocol.phases.map((phase): DashboardPhase => {
    const available = Date.parse(phase.availableAt ?? '');
    const start = Date.parse(phase.startsAt ?? '');
    const end = Date.parse(phase.endsAt ?? '');
    const time = now.getTime();
    const state = !Number.isFinite(available) || time < available ? 'locked'
      : time < start ? 'preview' : time < end ? 'active' : 'done';
    const durationWeeks = Math.max(0, phase.weekEnd - phase.weekStart + 1);
    // Count calendar dates in the response timezone, not 24-hour periods across DST.
    const dateParts = new Intl.DateTimeFormat('en-CA', {
      timeZone: data.timezone ?? 'Europe/Amsterdam', year: 'numeric', month: '2-digit', day: '2-digit',
    });
    const dayNumber = (date: Date) => {
      const parts = Object.fromEntries(dateParts.formatToParts(date).map((part) => [part.type, part.value]));
      return Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day)) / 86400000;
    };
    const currentPhaseWeek = state === 'active'
      ? Math.min(durationWeeks, Math.max(1, Math.floor((dayNumber(now) - dayNumber(new Date(start))) / 7) + 1)) : null;
    return { ...phase, state, durationWeeks, currentPhaseWeek,
      durationLabel: phase.weekStart > 0 ? `Duur: ${durationWeeks} ${durationWeeks === 1 ? 'week' : 'weken'}` : 'Nog niet ingepland', accessible: state !== 'locked',
      statusLabel: state === 'active' ? `Actief · week ${currentPhaseWeek} van ${durationWeeks}`
        : state === 'preview' ? 'Start volgende week' : state === 'done' ? 'Afgerond'
          : phase.weekStart ? `Start in protocolweek ${phase.weekStart}` : 'Nog niet ingepland',
      description: state === 'locked' ? undefined : phase.description,
      supplements: state === 'locked' ? [] : phase.supplements,
    };
  });
  const allowed = new Set(phases.flatMap((phase) => phase.supplements.map((item) => item.id)));
  return { ...data, protocol: { ...data.protocol, phases,
    orderItems: data.protocol.orderItems.filter((item) => allowed.has(item.id)),
    notifications: data.protocol.notifications.filter((notice) => notice.type !== 'next_phase'
      || phases.some((phase) => phase.id === notice.phaseId && phase.state === 'preview')),
  } };
}
