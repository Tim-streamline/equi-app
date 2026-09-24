import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loginDestination } from '../db/login-destination.ts';
test('login preserves private deep links but rejects external redirects and loops', () => {
  assert.equal(loginDestination('?returnTo=%2Faccount%2Fpreferences'), '/account/preferences');
  assert.equal(loginDestination('?returnTo=%2Fprotocol%3Ftab%3Dkalender'), '/protocol?tab=kalender');
  for (const value of ['//other.example/path', '/\\other.example/path', 'https://other.example', '/onboarding/welcome']) {
    assert.equal(loginDestination('?returnTo=' + encodeURIComponent(value)), '/home');
  }
  const post = '00000000-0000-4000-8000-000000000001';
  assert.equal(loginDestination('?communityPost=' + post), '/community/thread/' + post);
});
