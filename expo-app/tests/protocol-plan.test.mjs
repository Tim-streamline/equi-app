import assert from 'node:assert/strict';
import test from 'node:test';

import {
  ACTIVE_PROTOCOL_SQL,
  buildProtocolPlan,
  protocolSupplementRowsForDay,
  protocolSupplementRowsForWeek,
  protocolOrderItems,
  supplementsForProtocolWeek,
} from '../lib/protocol-plan.ts';

const phases = [
  { id: 'phase-2', order: 1, title: 'Opbouw', startAfterPreviousPhaseWeeks: 2 },
  { id: 'phase-1', order: 0, title: 'Herstel', startAfterPreviousPhaseWeeks: null },
];

const weeks = [
  { id: 'phase-1-week-1', protocolPhaseId: 'phase-1', number: 1, protocolWeekNumber: 1 },
  { id: 'phase-1-week-2', protocolPhaseId: 'phase-1', number: 2, protocolWeekNumber: 2 },
  { id: 'phase-1-week-3', protocolPhaseId: 'phase-1', number: 3, protocolWeekNumber: 3 },
  { id: 'phase-1-week-4', protocolPhaseId: 'phase-1', number: 4, protocolWeekNumber: 4 },
  { id: 'phase-2-week-1', protocolPhaseId: 'phase-2', number: 1, protocolWeekNumber: 3 },
  { id: 'phase-2-week-2', protocolPhaseId: 'phase-2', number: 2, protocolWeekNumber: 4 },
];

const supplements = [
  {
    id: 'supplement-1',
    protocolPhaseId: 'phase-1',
    name: 'Psylliumzaad',
    dosage: '176 g',
    aantalPerWeek: 4,
  },
  {
    id: 'supplement-2',
    protocolPhaseId: 'phase-2',
    name: 'Kamille',
    dosage: '25 ml',
    aantalPerWeek: 5,
  },
];

const supplementWeeks = [
  { id: 'link-1', protocolPhaseSupplementId: 'supplement-1', protocolPhaseWeekId: 'phase-1-week-3' },
  { id: 'link-2', protocolPhaseSupplementId: 'supplement-2', protocolPhaseWeekId: 'phase-2-week-1' },
];

test('only a published active protocol can be selected for the customer app', () => {
  assert.match(ACTIVE_PROTOCOL_SQL, /status = 'active'/);
  assert.match(ACTIVE_PROTOCOL_SQL, /published_at IS NOT NULL/);
  assert.match(ACTIVE_PROTOCOL_SQL, /ORDER BY published_at DESC/);
});

test('maps phase-local supplement weeks to overlapping protocol weeks', () => {
  const plan = buildProtocolPlan({ phases, weeks, supplements, supplementWeeks });

  assert.deepEqual(plan.map((phase) => phase.id), ['phase-1', 'phase-2']);
  assert.deepEqual(plan[0].supplements[0].protocolWeekNumbers, [3]);
  assert.deepEqual(plan[1].supplements[0].protocolWeekNumbers, [3]);
  assert.deepEqual(
    supplementsForProtocolWeek(plan, 3).map((supplement) => supplement.name),
    ['Psylliumzaad', 'Kamille'],
  );
});

test('builds the order list from planned supplements instead of protocol tasks', () => {
  const plan = buildProtocolPlan({ phases, weeks, supplements, supplementWeeks });

  assert.deepEqual(protocolOrderItems(plan), [
    { id: 'supplement-1', name: 'Psylliumzaad', dosage: '176 g', phaseTitles: ['Herstel'] },
    { id: 'supplement-2', name: 'Kamille', dosage: '25 ml', phaseTitles: ['Opbouw'] },
  ]);
});

test('builds the today supplement rows from the configured protocol week', () => {
  const plan = buildProtocolPlan({ phases, weeks, supplements, supplementWeeks });

  assert.deepEqual(protocolSupplementRowsForWeek(plan, 3), [
    { id: 'supplement-1', title: 'Psylliumzaad', dosage: '176 g' },
    { id: 'supplement-2', title: 'Kamille', dosage: '25 ml' },
  ]);
});

test('marks only the supplement intake recorded for the selected day as done', () => {
  const plan = buildProtocolPlan({ phases, weeks, supplements, supplementWeeks });

  assert.deepEqual(protocolSupplementRowsForDay(plan, 3, [
    {
      id: 'intake-1',
      protocolPhaseSupplementId: 'supplement-1',
      dosage: '176 g',
      done: 1,
      takenAt: '2026-08-23T08:30:00.000Z',
    },
  ]), [
    {
      id: 'supplement-1',
      title: 'Psylliumzaad',
      dosage: '176 g',
      done: true,
      intakeId: 'intake-1',
    },
    {
      id: 'supplement-2',
      title: 'Kamille',
      dosage: '25 ml',
      done: false,
      intakeId: null,
    },
  ]);
});
