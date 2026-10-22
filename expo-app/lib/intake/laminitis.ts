import type { IntakeAnswers, Section } from './schema';

export type Conditions = Record<string, string[]>;
export type SafetyTrigger = { id: string; when: Conditions; renew: boolean; block_while_matching: boolean; notice: string; popup: boolean; critical_fields: string[] };
export type SafetyConfig = {
  version: 1; section: string; marker_field: string; state_field: string; state_value: string; clear_value: string;
  actions: ('submit' | 'preview' | 'protocol')[];
  triggers: SafetyTrigger[];
  recovery: { require: Conditions; conditional: { when: Conditions; require: Conditions }[]; intro: string; after: string };
  messages: { blocked: string; confirm: string; update: string; acknowledge: string; pending: string };
  release_source: string;
};
// Preserve the existing wire/storage contract, including old episode identifiers.
export type LaminitisState = { episode_id?: string; kind?: string; client_marker?: string; released_at?: string; configuration?: SafetyConfig; requirements?: Conditions; recovery_fields?: { id: string; label: string; options: string[] }[] };
export function matches(conditions: Conditions, values: Record<string, unknown>) {
  return Object.keys(conditions).length > 0 && Object.entries(conditions).every(([key, accepted]) => accepted.includes(values[key] as string));
}
export function recoveryRequirements(config: SafetyConfig, answers: IntakeAnswers, safety?: LaminitisState | null): Conditions {
  const required = { ...config.recovery.require };
  for (const rule of config.recovery.conditional) if (matches(rule.when, answers[config.section] ?? {})) Object.assign(required, rule.require);
  return { ...required, ...safety?.requirements };
}
export function changedTrigger(config: SafetyConfig | null, answers: IntakeAnswers, section: string, field: string) {
  return config?.section === section ? config.triggers.find(t => field in t.when && matches(t.when, answers[section] ?? {})) : undefined;
}
export function laminitisHold(answers: IntakeAnswers, safety: LaminitisState | null, config: SafetyConfig | null, submitted = false) {
  if (safety?.episode_id && !safety.released_at) return true;
  // Submitted answers are immutable reports, not pending edits. Recovery is
  // recorded separately, and later intakes can replace the horse's marker.
  // Only a confirmed server release overrides those historical answers.
  if (submitted && safety?.episode_id && safety.released_at) return false;
  for (const rule of [config, safety?.configuration].filter((c): c is SafetyConfig => !!c)) {
    const values = answers[rule.section] ?? {};
    if (values[rule.marker_field] && values[rule.marker_field] !== safety?.client_marker) return true;
    if (rule.triggers.some(t => matches(t.when, values) && (!safety?.released_at || t.block_while_matching))) return true;
  }
  return false;
}
export function laminitisNotice(schema: Section[], safety: LaminitisState | null, config: SafetyConfig | null, answers: IntakeAnswers) {
  if (!config) return undefined;
  const trigger = config.triggers.find(t => t.id === safety?.kind) ?? config.triggers.find(t => matches(t.when, answers[config.section] ?? {}));
  return schema.flatMap(s => s.fields).find(f => f.id === trigger?.notice);
}

/** Resetting editable questions must not discard an unsynchronized safety report. */
export function safetyMarkers(answers: IntakeAnswers, configs: (SafetyConfig | null | undefined)[]): IntakeAnswers {
  const retained: IntakeAnswers = {};
  for (const config of configs) {
    if (!config) continue;
    const value = answers[config.section]?.[config.marker_field];
    if (value) retained[config.section] = { ...retained[config.section], [config.marker_field]: value };
  }
  return retained;
}
