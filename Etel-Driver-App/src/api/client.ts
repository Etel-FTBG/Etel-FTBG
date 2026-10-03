import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';
import { reportConnectivity } from '../net/connectivity';

// The whole backend is one endpoint: POST { action, ...fields } to boot.php,
// with the auth token as a Bearer header. See delivery/api/boot.php.
const API_BASE_URL: string =
  (Constants.expoConfig?.extra?.etelApiBaseUrl as string) ||
  'https://ethelmarket.com/delivery/api/boot.php';

const TOKEN_KEY = 'etel_driver_token';

// Without a timeout a stalled mobile connection leaves the UI spinning forever.
const REQUEST_TIMEOUT_MS = 20000;
const READ_RETRIES = 2;

// Only side-effect-free reads are retried after a network failure. Retrying a
// write (accept/start/arrived/cashout...) could apply it twice.
const RETRY_SAFE_ACTIONS = new Set([
  'driver_get_announcements',
  'driver_get_compliance_status',
  'driver_get_earnings_for_date',
  'driver_get_goal_tiers',
  'driver_get_kpi_summary',
  'driver_get_nearby_requests',
  'driver_get_profile',
  'driver_get_ride_history',
  'driver_get_topup_history',
  'get_ride_messages',
]);

export class ApiError extends Error {
  status: number;
  code?: string;
  constructor(message: string, status: number, code?: string) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

export async function getToken(): Promise<string | null> {
  return SecureStore.getItemAsync(TOKEN_KEY);
}

export async function setToken(token: string): Promise<void> {
  await SecureStore.setItemAsync(TOKEN_KEY, token);
}

export async function clearToken(): Promise<void> {
  await SecureStore.deleteItemAsync(TOKEN_KEY);
}

// Fired whenever a call comes back 401 (token expired/invalid). There is no
// refresh-token endpoint on this backend (boot.php:244-323), so the only
// correct response is to force a fresh login.
type UnauthorizedListener = () => void;
let unauthorizedListener: UnauthorizedListener | null = null;
export function onUnauthorized(listener: UnauthorizedListener) {
  unauthorizedListener = listener;
}

export async function fetchWithTimeout(url: string, init: RequestInit, timeoutMs: number): Promise<Response> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...init, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

export async function boot<T = any>(
  action: string,
  body: Record<string, unknown> = {},
  opts: { auth?: boolean } = { auth: true }
): Promise<T> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  };

  if (opts.auth !== false) {
    const token = await getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
  }

  const init: RequestInit = { method: 'POST', headers, body: JSON.stringify({ action, ...body }) };
  const attempts = RETRY_SAFE_ACTIONS.has(action) ? READ_RETRIES + 1 : 1;

  let res: Response | null = null;
  let timedOut = false;
  for (let i = 0; i < attempts && !res; i++) {
    try {
      res = await fetchWithTimeout(API_BASE_URL, init, REQUEST_TIMEOUT_MS);
    } catch (e) {
      timedOut = e instanceof Error && e.name === 'AbortError';
      // Surface the problem right away; waiting for every retry to finish
      // would leave the driver staring at a spinner with no explanation.
      reportConnectivity(false);
      if (i < attempts - 1) await new Promise((r) => setTimeout(r, 600 * (i + 1)));
    }
  }

  if (!res) {
    throw new ApiError(
      timedOut ? 'The server took too long to respond. Check your connection and try again.' : 'No connection. Check your internet and try again.',
      0,
      timedOut ? 'timeout' : 'network'
    );
  }
  reportConnectivity(true);

  let json: any = null;
  try {
    json = await res.json();
  } catch {
    // fall through, json stays null
  }

  // Server envelope is { success: bool, data: {...}, message?: string, v }
  // (boot.php:201-209's ok()/fail() helpers) — not { ok, error }.
  if (res.status === 401) {
    await clearToken();
    unauthorizedListener?.();
    throw new ApiError(json?.message || 'Session expired, please log in again', 401);
  }

  if (!res.ok || json?.success === false) {
    throw new ApiError(json?.message || `Request failed (${res.status})`, res.status);
  }

  return (json?.data ?? json) as T;
}
