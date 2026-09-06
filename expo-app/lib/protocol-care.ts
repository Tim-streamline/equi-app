import type { DashboardAdvice, DashboardProtocol } from './horse-dashboard';

export type CareAdvice = Omit<DashboardAdvice, 'action'> & {
  action?: DashboardAdvice['action'];
};

export type CareSection = {
  id: 'environment' | 'movement' | 'care' | 'monitoring';
  title: string;
  items: CareAdvice[];
};

// The dashboard already selects the horse's active, published protocol and
// applies Protocolbeheer's categories and personal instructions. Do not infer
// categories from titles or supplement the selection with catalog advice here.
export function careSections(
  protocol: Pick<DashboardProtocol, 'management' | 'movement'>,
): CareSection[] {
  const managementItems = (id: string) =>
    protocol.management.flatMap((group) => group.id === id ? group.items : []);

  const sections: CareSection[] = [
    { id: 'environment', title: 'Leefomgeving & weide', items: managementItems('environment') },
    { id: 'movement', title: 'Beweging & belasting', items: protocol.movement },
    { id: 'care', title: 'Lichamelijke zorg', items: managementItems('care') },
    { id: 'monitoring', title: 'Onderzoek', items: managementItems('monitoring') },
  ];

  return sections.filter((section) => section.items.length > 0);
}
