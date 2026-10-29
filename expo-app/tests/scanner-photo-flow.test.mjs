import assert from 'node:assert/strict';
import test from 'node:test';
import { missingPhotoCount, requiredPhotoSlots, resumePhotoStep, unreadableComposition } from '../lib/scanner-photo-flow.ts';

test('resume at the first unfinished photo step without discarding uploaded evidence', () => {
  assert.equal(resumePhotoStep(null), 'composition');
  const pending = { status: 'needs_photos', workflow_version: 2, photo_ids: { composition: 'saved' }, recognition: { decision: 'unknown' } };
  assert.equal(resumePhotoStep(pending), 'analysis');
  assert.equal(resumePhotoStep({ ...pending, photo_ids: { ...pending.photo_ids, analysis: 'saved-analysis' } }), 'front');
  assert.equal(resumePhotoStep({ ...pending, status: 'candidate' }), 'composition');
});

test('uncertain identity and service failure never become a bad-photo warning', () => {
  for (const recognition of [{ decision: 'uncertain' }, { decision: 'unknown' }, { decision: 'uncertain', ocr: { engine: 'unavailable', words: 0 } }, { decision: 'uncertain', ocr: { engine: 'tesseract-local-1', words: 12 } }]) {
    assert.equal(unreadableComposition({ recognition }), false);
  }
  const unreadable = { status: 'needs_photos', workflow_version: 2, recognition: { decision: 'uncertain', ocr: { engine: 'tesseract-local-1', words: 0 } } };
  assert.equal(unreadableComposition(unreadable), true);
  assert.equal(resumePhotoStep(unreadable), 'composition');
});

test('only composition is needed before recognition; unknown or changed recipes require all three photos', () => {
  assert.deepEqual(requiredPhotoSlots(null), ['composition']);
  assert.equal(missingPhotoCount(null, {}), 1);
  assert.equal(missingPhotoCount(null, { composition: {} }), 0);
  for (const decision of ['changed', 'unknown', 'uncertain']) {
    const pending = { status: 'needs_photos', workflow_version: 2, photo_ids: { composition: 'saved' }, recognition: { decision } };
    assert.deepEqual(requiredPhotoSlots(pending), ['composition', 'analysis', 'front']);
    assert.equal(missingPhotoCount(pending, {}), 2);
    assert.equal(missingPhotoCount(pending, { analysis: {} }), 1);
    assert.equal(missingPhotoCount(pending, { analysis: {}, front: {} }), 0);
  }
});

test('resuming a submission retains uploaded evidence and legacy submissions keep their original required slots', () => {
  assert.equal(missingPhotoCount({ workflow_version: 2, product_version_id: 'draft', photo_ids: { composition: 'a', analysis: 'b', front: 'c' } }, {}), 0);
  assert.deepEqual(requiredPhotoSlots({ workflow_version: 1, photo_ids: {} }), ['composition', 'analysis']);
});


test('a failed submission resumes additional photos before its first product revision exists', () => {
  const pending = { status: 'failed', workflow_version: 2, product_id: 'new-product', photo_ids: { composition: 'a', analysis: 'b', front: 'c' } };
  assert.equal(resumePhotoStep(pending), 'front');
  assert.equal(missingPhotoCount(pending, {}), 0);
});


test('a replaced composition must be checked again even when the submission already has a draft product', () => {
  const pending = { status: 'awaiting_composition', workflow_version: 2, product_id: 'draft', photo_ids: { composition: 'replacement', analysis: 'saved-analysis', front: 'saved-front' } };
  assert.equal(resumePhotoStep(pending), 'composition');
  assert.deepEqual(requiredPhotoSlots(pending), ['composition']);
  assert.equal(missingPhotoCount(pending, {}), 0);
  assert.equal(resumePhotoStep({ ...pending, status: 'candidate' }), 'composition');
});
