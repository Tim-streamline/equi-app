export type LoginResponse = {
  endpoint: string;
  token: string;
  expires_in: number;
  user: { id: string | number; name: string; email: string; avatar_initial?: string };
};
type Session = LoginResponse & { expiresAt: number };
let session: Session | null = null;
let revision = 0;
let pending: Promise<LoginResponse | null> | null = null;
export const getApiBaseUrl = () => '';

export function forgetSession() {
  revision++;
  session = null;
  pending = null;
}
function accept(data: LoginResponse) {
  session = { ...data, expiresAt: Date.now() + data.expires_in * 1000 };
  return data;
}
async function request(path: string, body: object, signal?: AbortSignal) {
  // Fetch the current token even after session rotation in this or another tab.
  const csrf = await fetch('/web-session/csrf', { credentials: 'same-origin', cache: 'no-store', signal });
  if (!csrf.ok) throw new Error('Verbinding niet beschikbaar. Probeer opnieuw.');
  const { token } = await csrf.json();
  return fetch(`/web-session/${path}`, {
    method: 'POST', credentials: 'same-origin', cache: 'no-store', signal,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
    body: JSON.stringify(body),
  });
}
export async function login(email: string, password: string): Promise<LoginResponse> {
  const current = ++revision;
  const response = await request('login', { email, password });
  if (!response.ok) throw new Error(response.status === 401 ? 'E-mailadres of wachtwoord is onjuist.' : 'Inloggen is niet gelukt. Probeer opnieuw.');
  const data = await response.json();
  if (current !== revision) throw new Error('Je sessie is gewijzigd. Probeer opnieuw.');
  return accept(data);
}
export async function getSession(signal?: AbortSignal, force = false): Promise<LoginResponse | null> {
  if (!force && session && session.expiresAt > Date.now() + 60_000) return session;
  if (pending) return pending;
  const current = revision;
  const expectedUser = session?.user.id;
  const operation = (async () => {
    const response = await request('token', {}, signal);
    if (current !== revision) throw new Error('Je sessie is gewijzigd.');
    if (response.status === 401) {
      forgetSession();
      if (typeof window !== 'undefined') window.dispatchEvent(new Event('equinova:session-expired'));
      return null;
    }
    if (!response.ok) throw new Error('Verbinding niet beschikbaar. Probeer opnieuw.');
    const data: LoginResponse = await response.json();
    if (current !== revision) throw new Error('Je sessie is gewijzigd.');
    if (expectedUser && String(expectedUser) !== String(data.user.id)) {
      forgetSession();
      if (typeof window !== 'undefined') window.dispatchEvent(new Event('equinova:session-expired'));
      throw new Error('Je account is gewijzigd in een ander tabblad.');
    }
    return accept(data);
  })();
  pending = operation;
  try { return await operation; }
  finally { if (pending === operation) pending = null; }
}
export async function logout() {
  const response = await request('logout', {});
  if (!response.ok) throw new Error('Uitloggen is niet gelukt. Probeer opnieuw.');
  forgetSession();
}
