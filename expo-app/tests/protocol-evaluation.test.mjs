import assert from 'node:assert/strict';
import test from 'node:test';
import { evaluationComplete, evaluationMoney, horsePossessive } from '../lib/protocol-evaluation.ts';

test('evaluation requires every current question and rejects whitespace-only answers', () => {
  assert.equal(evaluationComplete({ changes: 'Changes', experience: 'Experience' }, { changes: 'Goed' }), false);
  assert.equal(evaluationComplete({ changes: 'Changes' }, { changes: '  ' }), false);
  assert.equal(evaluationComplete({ changes: 'Changes' }, { changes: 'Verbeterd' }), true);
  assert.equal(evaluationComplete({}, {}), false);
});

test('Dutch possessives and discounted price remain readable', () => {
  assert.equal(horsePossessive('Moos'), "Moos'");
  assert.equal(horsePossessive('Alano'), "Alano's");
  assert.match(evaluationMoney(13230), /132,30/);
});
