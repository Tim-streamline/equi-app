import type { Field, Section } from './schema';

type Row = Record<string, unknown>;

function bool(value: unknown): boolean {
  return value === true || value === 1 || value === '1';
}

function json<T>(value: unknown): T | undefined {
  if (value === null || value === undefined || value === '') return undefined;
  if (typeof value !== 'string') return value as T;
  try {
    return JSON.parse(value) as T;
  } catch {
    return undefined;
  }
}

export function isConfiguredNoneOption(option: string, noneOptions: readonly string[]): boolean {
  const normalized = option.trim().toLowerCase();
  return noneOptions.some((candidate) => candidate.trim().toLowerCase() === normalized);
}

/** Convert PowerSync's flat SQLite rows into the existing app schema contract. */
export function buildIntakeSchema(sectionRows: Row[], fieldRows: Row[]): Section[] {
  const fieldsBySection = new Map<string, Field[]>();

  for (const row of fieldRows) {
    if (!bool(row.active ?? 1)) continue;
    const sectionId = String(row.section_id ?? '');
    const field: Field = {
      id: String(row.key),
      label: String(row.label),
      type: String(row.type) as Field['type'],
      hint: row.hint == null ? undefined : String(row.hint),
      required: bool(row.required),
      optional: bool(row.optional),
      unit: row.unit == null ? undefined : String(row.unit),
      step: row.step == null ? undefined : Number(row.step),
      tall: bool(row.tall),
      lines: row.lines == null ? undefined : Number(row.lines),
      placeholder: row.placeholder == null ? undefined : String(row.placeholder),
      link: json<Field['link']>(row.link),
      options: json<string[]>(row.options),
      showIf: json<Field['showIf']>(row.show_if),
      flagIf: json<Field['flagIf']>(row.flag_if),
      criticalIf: json<Field['criticalIf']>(row.critical_if),
      protocolIf: json<Field['protocolIf']>(row.protocol_if),
      sub: json<Field['sub']>(row.repeater_sub),
    };
    Object.defineProperty(field, '__order', { value: Number(row.order ?? 0), enumerable: false });
    const fields = fieldsBySection.get(sectionId) ?? [];
    fields.push(field);
    fieldsBySection.set(sectionId, fields);
  }

  return sectionRows
    .filter((row) => bool(row.active ?? 1))
    .sort((a, b) => Number(a.order ?? 0) - Number(b.order ?? 0))
    .map((row) => ({
      id: String(row.key),
      nr: Number(row.order ?? 0),
      title: String(row.title),
      intro: row.intro == null ? '' : String(row.intro),
      minutes: Number(row.minutes ?? 0),
      icon: String(row.icon ?? 'sparkles') as Section['icon'],
      sub: row.subtitle == null ? '' : String(row.subtitle),
      fields: (fieldsBySection.get(String(row.id)) ?? []).sort(
        (a, b) => Number((a as Field & { __order?: number }).__order ?? 0) - Number((b as Field & { __order?: number }).__order ?? 0),
      ),
    }));
}
