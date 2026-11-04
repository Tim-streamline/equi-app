// Keeps the sign-in in the phone's secure storage (Keychain/Keystore) as a
// device token, never the password. POST /api/auth/token trades it for a
// short-lived PowerSync JWT whenever PowerSync asks for fresh credentials
// (see POWERSYNC_TOKEN_TTL on the Laravel side).

import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

const KEY = 'equinova.auth.v2';
/** App versions before device tokens kept the password here; it is replaced on first use. */
const LEGACY_KEY = 'equinova:auth:v1';
/** Marks this installation. AsyncStorage is wiped on uninstall, the iOS Keychain is not. */
const INSTALL_KEY = 'equinova:installed';
// Readable for background sync once the phone has been unlocked; never restored to another device.
const STORE_OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY };

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

type Account = { email: string; userId: string; endpoint: string };
export type DeviceCredentials = Account & { deviceToken: string };
/** Either a device token or, until it is exchanged once, an older version's stored password. */
export type StoredCredentials = DeviceCredentials | (Account & { password: string });

// A reinstalled app must not sign back in with the previous installation's device token.
let installChecked: Promise<void> | null = null;
function forgetPreviousInstall(): Promise<void> {
  installChecked ??= (async () => {
    if (await AsyncStorage.getItem(INSTALL_KEY)) return;
    await SecureStore.deleteItemAsync(KEY, STORE_OPTIONS);
    await AsyncStorage.setItem(INSTALL_KEY, '1');
  })().catch(error => { installChecked = null; throw error; });
  return installChecked;
}

export async function saveCredentials(creds: DeviceCredentials) {
  await forgetPreviousInstall();
  await SecureStore.setItemAsync(KEY, JSON.stringify(creds), STORE_OPTIONS);
  await AsyncStorage.removeItem(LEGACY_KEY);
}

/**
 * Read from secure storage on every call on purpose; do not cache the result in memory.
 * It holds the device token, which can mint PowerSync JWTs for up to 90 days (extended on
 * every use), so it should only be in memory while a token is minted. The fresh read also
 * makes sign-out and account switches take effect at once, with no cache to invalidate.
 * If these reads ever prove slow, cache only the non-secret account fields (userId, email,
 * endpoint) and keep reading the device token here when minting.
 */
export async function loadCredentials(): Promise<StoredCredentials | null> {
  await forgetPreviousInstall();
  const raw = await SecureStore.getItemAsync(KEY, STORE_OPTIONS) ?? await AsyncStorage.getItem(LEGACY_KEY);
  return raw ? (JSON.parse(raw) as StoredCredentials) : null;
}

export async function clearCredentials() {
  await SecureStore.deleteItemAsync(KEY, STORE_OPTIONS);
  await AsyncStorage.removeItem(LEGACY_KEY);
}

export type LoginResponse = {
  endpoint: string;
  token: string;
  expires_in: number;
  /** Returned when the request names this device (`device_name`). */
  device_token?: string;
  user: {
    id: number | string;
    name: string;
    email: string;
    avatar_initial?: string;
  };
};

/**
 * The credentials to keep after signing in or registering. A fresh sign-in needs a backend that
 * issues device tokens, so deploy the backend before releasing an app version with this code.
 */
export function deviceCredentials(res: LoginResponse): DeviceCredentials {
  if (!res.device_token) throw new Error('Inloggen is niet gelukt. Probeer het later opnieuw.');
  return { email: res.user.email, userId: String(res.user.id), endpoint: res.endpoint, deviceToken: res.device_token };
}

const DEVICE_NAME = `${Platform.OS} ${Platform.Version}`;
// Signed into each fresh JWT so the API and PowerSync agree on the protocol format.
const CLIENT_CAPABILITIES = ['protocol-doses'];

async function authRequest(path: string, body: object, signal?: AbortSignal): Promise<LoginResponse> {
  const res = await fetch(`${apiBaseUrl}/api/auth/${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ ...body, capabilities: CLIENT_CAPABILITIES }),
    signal,
  });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`Login failed (${res.status}): ${text || res.statusText}`);
  }
  return (await res.json()) as LoginResponse;
}

export function login(email: string, password: string, signal?: AbortSignal): Promise<LoginResponse> {
  return authRequest('login', { email, password, device_name: DEVICE_NAME }, signal);
}

/** A fresh PowerSync JWT for this device. Fails with `Login failed (401)` once the device token is revoked or expired. */
export function exchangeDeviceToken(deviceToken: string, signal?: AbortSignal): Promise<LoginResponse> {
  return authRequest('token', { device_token: deviceToken }, signal);
}

/** Signs this device out on the server. Best effort: an unused token also expires after 90 days. */
export async function revokeDeviceToken(deviceToken: string): Promise<void> {
  await fetch(`${apiBaseUrl}/api/auth/logout`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ device_token: deviceToken }),
  }).catch(() => {});
}

export type RegistrationInput = { name: string; email: string; password: string; password_confirmation: string };
export type RegistrationChallenge = { registration_token: string; email: string; expires_in: number };
export type RegistrationCompletion = { registration_token: string };
async function registrationRequest(path: string, input: RegistrationInput | RegistrationCompletion & { device_name?: string }, signal?: AbortSignal) {
  const response = await fetch(`${apiBaseUrl}/api/auth/${path}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ ...input, capabilities: CLIENT_CAPABILITIES }), signal,
  });
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
  return registrationRequest('register/complete', { ...input, device_name: DEVICE_NAME });
}

export async function checkRegistration(input: RegistrationCompletion, signal?: AbortSignal): Promise<{ status: 'pending' | 'confirmed' | 'expired' }> {
  return registrationRequest('register/status', input, signal);
}

export async function requestPasswordReset(email: string): Promise<{ message: string }> {
  const response = await fetch(`${apiBaseUrl}/api/auth/forgot-password`, {
    method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email: email.trim() }),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(response.status === 429 ? 'Te veel pogingen. Wacht een minuut en probeer opnieuw.' : String(Object.values(data.errors ?? {}).flat()[0] ?? 'De resetlink kon niet worden aangevraagd. Probeer opnieuw.'));
  return data;
}
