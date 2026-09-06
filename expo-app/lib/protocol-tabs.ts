export const PROTOCOL_TABS = [
  { key: 'vandaag', label: 'Vandaag' },
  { key: 'kalender', label: 'Kalender' },
  { key: 'voeding', label: 'Voeding' },
  { key: 'zorg', label: 'Zorg' },
  { key: 'analyse', label: 'Analyse' },
] as const;

export type ProtocolTab = (typeof PROTOCOL_TABS)[number]['key'];

export function protocolTab(value: unknown): ProtocolTab | undefined {
  if (value === 'management' || value === 'beweging') return 'zorg';
  return PROTOCOL_TABS.find((tab) => tab.key === value)?.key;
}
