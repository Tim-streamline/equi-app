export type LibraryRecommendation = {
  id: string;
  title: string;
  format: string;
  description?: string;
  durationLabel?: string;
  creditCost: number;
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
  state: "active" | "done" | "upcoming";
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
      item.format === "article"
        ? "/(tabs)/library/article/[id]"
        : "/(tabs)/library/video/[id]",
    params: { id: item.id },
  };
}
