import { syncRequest, SyncRequestError } from '../../expo-app/lib/sync-request.ts';

export type LoginResponse = {
  endpoint: string;
  token: string;
  expires_in: number;
  logout_id?: string;
  user: { id: string | number; name: string; email: string; avatar_initial?: string };
};
type Session = LoginResponse & { expiresAt: number };
let session: Session | null = null;
let revision = 0;
let pending: Promise<LoginResponse | null> | null = null;
export const getApiBaseUrl = () => '';
// Signed into each token this browser receives, so the API and PowerSync serve the shared
// protocol screens the per-dose format. Without it the server keeps the legacy format.
const CLIENT_CAPABILITIES = ['protocol-doses'];

export const LOCAL_SIGNOUT_KEY = 'equinova:signed-out';
type LocalSignout = { id: string; logoutId?: string; pending: boolean };
let fallbackSignout: string | null = null;
let logoutAttempt: Promise<boolean> | null = null;
let logoutController: AbortController | null = null;
let authenticating = 0;
let authTail: Promise<unknown> = Promise.resolve();
const readSignout = () => typeof localStorage === 'undefined' ? fallbackSignout : localStorage.getItem(LOCAL_SIGNOUT_KEY);
function writeSignout(value: string | null) {
  if (typeof localStorage !== 'undefined') {
    if (value === null) localStorage.removeItem(LOCAL_SIGNOUT_KEY);
    else localStorage.setItem(LOCAL_SIGNOUT_KEY, value);
  }
  fallbackSignout = value;
}
export const isLocallySignedOut = () => readSignout() !== null;
function signoutIntent() {
  const value = readSignout();
  if (!value) return null;
  try { return (JSON.parse(value) as LocalSignout).id; } catch { return value; }
}

// Cookies are shared across tabs. Serialize logout requests and explicit logins,
// with bounded network operations so a failed cleanup cannot hold up login forever.
async function withAuthLock<T>(work: () => Promise<T>): Promise<T> {
  if (typeof navigator !== 'undefined' && navigator.locks) return navigator.locks.request('equinova:auth-mutation', work);
  const result = authTail.then(work);
  authTail = result.catch(() => {});
  return result;
}

async function authenticate(work: (signal: AbortSignal) => Promise<LoginResponse>): Promise<LoginResponse> {
  const current = ++revision;
  const signout = signoutIntent();
  authenticating++;
  logoutController?.abort();
  const check = () => {
    if (current !== revision || signoutIntent() !== signout) throw new Error('Je sessie is gewijzigd. Probeer opnieuw.');
  };
  try {
    return await withAuthLock(async () => {
      check();
      const data = await syncRequest(20_000, signal => work(signal));
      check();
      // Failed login leaves the persistent sign-out intact. Only explicit,
      // successful authentication permits automatic restoration again.
      writeSignout(null);
      return accept(data);
    });
  } finally { authenticating--; }
}

export function retryPendingLogout(): Promise<boolean> {
  if (logoutAttempt) return logoutAttempt;
  if (authenticating) return Promise.resolve(false);
  const saved = readSignout();
  if (!saved) return Promise.resolve(false);
  let state: LocalSignout;
  try { state = JSON.parse(saved); } catch { return Promise.resolve(false); }
  // Never send an unscoped deferred logout to an older backend.
  if (!state.pending || !state.logoutId) return Promise.resolve(false);
  const controller = new AbortController();
  logoutController = controller;
  const check = () => {
    if (controller.signal.aborted || authenticating || readSignout() !== saved) throw new Error('Logout superseded');
  };
  const attempt = withAuthLock(async () => {
    check();
    const response = await syncRequest(20_000, signal => request('logout', { logout_id: state.logoutId }, signal, check), controller.signal);
    check();
    if (!response.ok) return false;
    writeSignout(JSON.stringify({ ...state, pending: false }));
    return true;
  }).catch(() => false);
  logoutAttempt = attempt;
  void attempt.finally(() => {
    if (logoutAttempt === attempt) logoutAttempt = null;
    if (logoutController === controller) logoutController = null;
  });
  return attempt;
}

export function forgetSession() {
  revision++;
  session = null;
  pending = null;
}
function accept(data: LoginResponse) {
  session = { ...data, expiresAt: Date.now() + data.expires_in * 1000 };
  return data;
}
async function request(path: string, body: object, signal?: AbortSignal, check = () => {}) {
  check();
  // Fetch the current token even after session rotation in this or another tab.
  const csrf = await fetch('/web-session/csrf', { credentials: 'same-origin', cache: 'no-store', signal });
  if (!csrf.ok) throw new Error('Verbinding niet beschikbaar. Probeer opnieuw.');
  const { token } = await csrf.json();
  check();
  if (signal?.aborted) throw new SyncRequestError('De verbinding is onderbroken. Probeer opnieuw.', 'cancelled');
  return fetch(`/web-session/${path}`, {
    method: 'POST', credentials: 'same-origin', cache: 'no-store', signal,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
    body: JSON.stringify(body),
  });
}
export async function login(email: string, password: string): Promise<LoginResponse> {
  return authenticate(async signal => {
    const response = await request('login', { email, password, capabilities: CLIENT_CAPABILITIES }, signal);
    if (!response.ok) throw new Error(response.status === 401 ? 'E-mailadres of wachtwoord is onjuist.' : 'Inloggen is niet gelukt. Probeer opnieuw.');
    return response.json();
  });
}
export async function getSession(signal?: AbortSignal, force = false): Promise<LoginResponse | null> {
  if (isLocallySignedOut()) return null;
  if (!force && session && session.expiresAt > Date.now() + 60_000) return session;
  if (pending) {
    if (!force) return pending;
    // An in-flight request may have started before subscription activation.
    // Finish it, then mint a token that reflects the new access state.
    const current = revision;
    await pending;
    if (current !== revision || isLocallySignedOut()) throw new Error('Je sessie is gewijzigd.');
    if (signal?.aborted) throw new Error('Request cancelled.');
    return getSession(signal, true);
  }
  const current = revision;
  const expectedUser = session?.user.id;
  const operation = syncRequest(20_000, async (requestSignal, check) => {
    const response = await request('token', { capabilities: CLIENT_CAPABILITIES }, requestSignal);
    check();
    if (current !== revision || isLocallySignedOut()) throw new Error('Je sessie is gewijzigd.');
    if (response.status === 401) {
      forgetSession();
      if (typeof window !== 'undefined') window.dispatchEvent(new Event('equinova:session-expired'));
      return null;
    }
    if (!response.ok) throw new Error('Verbinding niet beschikbaar. Probeer opnieuw.');
    const data: LoginResponse = await response.json();
    check();
    if (current !== revision || isLocallySignedOut()) throw new Error('Je sessie is gewijzigd.');
    if (expectedUser && String(expectedUser) !== String(data.user.id)) {
      forgetSession();
      if (typeof window !== 'undefined') window.dispatchEvent(new Event('equinova:session-expired'));
      throw new Error('Je account is gewijzigd in een ander tabblad.');
    }
    return accept(data);
  }, signal);
  pending = operation;
  try { return await operation; }
  finally { if (pending === operation) pending = null; }
}
export function logout() {
  if (!isLocallySignedOut()) writeSignout(JSON.stringify({
    id: crypto.randomUUID(), logoutId: session?.logout_id, pending: true,
  } satisfies LocalSignout));
  forgetSession();
  void retryPendingLogout();
}

export type RegistrationInput = { name: string; email: string; password: string; password_confirmation: string };
export type RegistrationChallenge = { registration_token: string; email: string; expires_in: number };
export type RegistrationCompletion = { registration_token: string };
async function registrationRequest(path: string, input: object, signal?: AbortSignal) {
  const response = await request(path, input, signal);
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const messages = Object.values(data.errors ?? {}).flat();
    throw new Error(response.status === 429 ? 'Te veel pogingen. Wacht een minuut en probeer opnieuw.' : String(messages[0] ?? data.message ?? 'Registreren is niet gelukt. Probeer opnieuw.'));
  }
  return data;
}
export async function register(input: RegistrationInput): Promise<RegistrationChallenge> {
  return registrationRequest('register', input);
}
export async function completeRegistration(input: RegistrationCompletion): Promise<LoginResponse> {
  return authenticate(signal => registrationRequest('register/complete', { ...input, capabilities: CLIENT_CAPABILITIES }, signal));
}

export async function checkRegistration(input: RegistrationCompletion, signal?: AbortSignal): Promise<{ status: 'pending' | 'confirmed' | 'expired' }> {
  return registrationRequest('register/status', input, signal);
}

export async function requestPasswordReset(email: string): Promise<{ message: string }> {
  const response = await request('forgot-password', { email: email.trim() });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(response.status === 429 ? 'Te veel pogingen. Wacht een minuut en probeer opnieuw.' : String(Object.values(data.errors ?? {}).flat()[0] ?? 'De resetlink kon niet worden aangevraagd. Probeer opnieuw.'));
  return data;
}
