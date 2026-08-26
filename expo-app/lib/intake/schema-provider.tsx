import { createContext, useContext, useMemo, type ReactNode } from 'react';
import { useQuery } from '@powersync/react';

import {
  INTAKE_DISCLAIMER_LONG,
  INTAKE_DISCLAIMER_SHORT,
  INTAKE_NONE_OPTIONS,
  INTAKE_SCHEMA,
  type Section,
} from './schema';
import { buildIntakeSchema } from './config';

type IntakeSchemaContextValue = {
  schema: Section[];
  disclaimerShort: string;
  disclaimerLong: string;
  noneOptions: string[];
  source: 'backend' | 'fallback';
};

const IntakeSchemaContext = createContext<IntakeSchemaContextValue>({
  schema: INTAKE_SCHEMA,
  disclaimerShort: INTAKE_DISCLAIMER_SHORT,
  disclaimerLong: INTAKE_DISCLAIMER_LONG,
  noneOptions: INTAKE_NONE_OPTIONS,
  source: 'fallback',
});

export function IntakeSchemaProvider({ children }: { children: ReactNode }) {
  const { data: questionnaireRows } = useQuery<Record<string, unknown>>(
    `SELECT * FROM intake_questionnaires WHERE active = 1 ORDER BY updated_at DESC LIMIT 1`,
  );
  const questionnaire = questionnaireRows?.[0];
  const questionnaireId = String(questionnaire?.id ?? '');
  const { data: sectionRows } = useQuery<Record<string, unknown>>(
    `SELECT * FROM intake_sections WHERE questionnaire_id = ? AND active = 1 ORDER BY "order"`,
    [questionnaireId],
  );
  const { data: fieldRows } = useQuery<Record<string, unknown>>(
    `SELECT intake_fields.*
     FROM intake_fields
     INNER JOIN intake_sections ON intake_sections.id = intake_fields.section_id
     WHERE intake_sections.questionnaire_id = ?
       AND intake_sections.active = 1
       AND intake_fields.active = 1
     ORDER BY intake_sections."order", intake_fields."order"`,
    [questionnaireId],
  );

  const value = useMemo<IntakeSchemaContextValue>(() => {
    const configured = questionnaire
      ? buildIntakeSchema([...(sectionRows ?? [])], [...(fieldRows ?? [])])
      : [];
    if (!questionnaire || configured.length === 0) {
      return {
        schema: INTAKE_SCHEMA,
        disclaimerShort: INTAKE_DISCLAIMER_SHORT,
        disclaimerLong: INTAKE_DISCLAIMER_LONG,
        noneOptions: INTAKE_NONE_OPTIONS,
        source: 'fallback',
      };
    }

    return {
      schema: configured,
      disclaimerShort: String(questionnaire.disclaimer_short ?? INTAKE_DISCLAIMER_SHORT),
      disclaimerLong: String(questionnaire.disclaimer_long ?? INTAKE_DISCLAIMER_LONG),
      noneOptions: parseStringArray(questionnaire.none_options) ?? INTAKE_NONE_OPTIONS,
      source: 'backend',
    };
  }, [questionnaire, sectionRows, fieldRows]);

  return <IntakeSchemaContext.Provider value={value}>{children}</IntakeSchemaContext.Provider>;
}

function parseStringArray(value: unknown): string[] | undefined {
  if (Array.isArray(value) && value.every((item) => typeof item === 'string')) return value;
  if (typeof value !== 'string') return undefined;
  try {
    const parsed = JSON.parse(value);
    return Array.isArray(parsed) && parsed.every((item) => typeof item === 'string')
      ? parsed
      : undefined;
  } catch {
    return undefined;
  }
}

export function useIntakeSchema(): IntakeSchemaContextValue {
  return useContext(IntakeSchemaContext);
}
