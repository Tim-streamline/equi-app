export const PROTOCOL_ADVICE_LAYOUTS = [
  'normal',
  'link_to_library',
  'roughage',
  'supplementary_feed',
] as const;

export type ProtocolAdviceLayout = (typeof PROTOCOL_ADVICE_LAYOUTS)[number];

export type ProtocolAdviceItem = {
  id: string;
  title?: unknown;
  description?: unknown;
  layout?: unknown;
  [key: string]: unknown;
};

export type ProtocolAdviceCategory = {
  key: 'voeding' | 'management' | 'beweging';
  items: ProtocolAdviceItem[];
};

export type ProtocolAdviceSection = {
  layout: ProtocolAdviceLayout;
  items: ProtocolAdviceItem[];
};

export function adviceLayout(value: unknown): ProtocolAdviceLayout {
  return PROTOCOL_ADVICE_LAYOUTS.includes(value as ProtocolAdviceLayout)
    ? value as ProtocolAdviceLayout
    : 'normal';
}

export function groupProtocolAdvice(input: {
  voeding: ProtocolAdviceItem[];
  management: ProtocolAdviceItem[];
  beweging: ProtocolAdviceItem[];
}): ProtocolAdviceCategory[] {
  return [
    { key: 'voeding', items: input.voeding },
    { key: 'management', items: input.management },
    { key: 'beweging', items: input.beweging },
  ];
}

export function advicePresentationSections(items: ProtocolAdviceItem[]): ProtocolAdviceSection[] {
  const byLayout = new Map<ProtocolAdviceLayout, ProtocolAdviceItem[]>();

  for (const item of items) {
    const layout = adviceLayout(item.layout);
    byLayout.set(layout, [...(byLayout.get(layout) ?? []), item]);
  }

  return (['roughage', 'link_to_library', 'supplementary_feed', 'normal'] as const)
    .filter((layout) => byLayout.has(layout))
    .map((layout) => ({ layout, items: byLayout.get(layout) ?? [] }));
}
