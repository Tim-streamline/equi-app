// Persists the PowerSync credentials we get from POST /api/auth/login so the
// app can re-connect on cold start without re-prompting. Tokens expire (see
// POWERSYNC_TOKEN_TTL on the Laravel side) — fetchCredentials() will hit
// `/api/auth/login` again whenever PowerSync asks for fresh credentials.

import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'equinova:auth:v1';

// Default for `adb reverse` / iOS simulator. Override at runtime when running
// on a physical device by calling setApiBaseUrl().
const DEFAULT_API_BASE =
  process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost';

let apiBaseUrl = DEFAULT_API_BASE;

export function getApiBaseUrl() {
  return apiBaseUrl;
}

export function setApiBaseUrl(url: string) {
  apiBaseUrl = url.replace(/\/+$/, '');
}

export type StoredCredentials = {
  email: string;
  password: string;
  userId: string;
  endpoint: string;
};

export async function saveCredentials(creds: StoredCredentials) {
  await AsyncStorage.setItem(KEY, JSON.stringify(creds));
}

export async function loadCredentials(): Promise<StoredCredentials | null> {
  const raw = await AsyncStorage.getItem(KEY);
  return raw ? (JSON.parse(raw) as StoredCredentials) : null;
}

export async function clearCredentials() {
  await AsyncStorage.removeItem(KEY);
}

export type LoginResponse = {
  endpoint: string;
  token: string;
  expires_in: number;
  user: {
    id: number | string;
    name: string;
    email: string;
    avatar_initial?: string;
  };
};

export async function login(email: string, password: string): Promise<LoginResponse> {
  const res = await fetch(`${apiBaseUrl}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`Login failed (${res.status}): ${body || res.statusText}`);
  }
  return (await res.json()) as LoginResponse;
}
