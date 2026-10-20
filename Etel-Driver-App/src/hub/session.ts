import { useEffect, useState } from 'react';
import * as SecureStore from 'expo-secure-store';
import { boot, setToken, clearToken } from '../api/client';
import { stopDriverLocationTracking } from '../location/backgroundLocationTask';
import { unregisterPushNotifications, registerForPushNotifications } from '../notifications/registerPush';
import { setOnlineState } from '../online/onlineState';

// One EtelID sign-in for the whole hub. The backend's etelid_login finds the member's account and returns its
// token plus its role (customer, driver, courier, service_giver or admin). The hub keeps that here and hands the
// same login to every web app, so nobody signs in again inside a company app.
export type EtelRole = 'customer' | 'driver' | 'courier' | 'service_giver' | 'admin';

export interface HubSession {
  id: number;
  name: string;
  phone: string;
  role: EtelRole;
  etelId: string | null;
  ssoToken: string | null;
  token: string;
}

const KEY = 'etel_hub_session';
let current: HubSession | null = null;
let loaded = false;
const listeners = new Set<(s: HubSession | null) => void>();

function emit() {
  listeners.forEach((l) => l(current));
}

export function getSession(): HubSession | null {
  return current;
}

export function subscribeSession(l: (s: HubSession | null) => void): () => void {
  listeners.add(l);
  return () => {
    listeners.delete(l);
  };
}

export async function loadSession(): Promise<HubSession | null> {
  if (loaded) return current;
  loaded = true;
  try {
    const raw = await SecureStore.getItemAsync(KEY);
    current = raw ? (JSON.parse(raw) as HubSession) : null;
  } catch {
    current = null;
  }
  emit();
  return current;
}

interface LoginResponse {
  token: string;
  user: { id: number; name: string; phone: string; role: EtelRole };
  etel_id?: string;
  sso_token?: string | null;
}

export async function hubSignIn(identifier: string, password: string, totp?: string): Promise<HubSession> {
  // No role is sent: any EtelID can sign in here, and the account's own role decides which apps open.
  const res = await boot<LoginResponse>(
    'etelid_login',
    { identifier, password, ...(totp ? { totp_code: totp } : {}) },
    { auth: false }
  );
  await setToken(res.token);
  const s: HubSession = {
    id: res.user.id,
    name: res.user.name,
    phone: res.user.phone,
    role: res.user.role,
    etelId: res.etel_id || null,
    ssoToken: res.sso_token || null,
    token: res.token,
  };
  await SecureStore.setItemAsync(KEY, JSON.stringify(s));
  current = s;
  emit();
  registerForPushNotifications().catch(() => {});
  return s;
}

export async function hubSignOut(): Promise<void> {
  await stopDriverLocationTracking().catch(() => {});
  await unregisterPushNotifications().catch(() => {});
  await clearToken().catch(() => {});
  await SecureStore.deleteItemAsync(KEY).catch(() => {});
  setOnlineState(false);
  current = null;
  emit();
}

// The apps each account may open. Customer, Ride and EtelID are open to every account (the backend lets any EtelID
// reach the customer side); the working-role apps open only for that role. An admin opens everything.
const OPEN_TO_ALL = ['ride', 'customer', 'id'];
const ROLE_APP: Record<string, EtelRole> = { driver: 'driver', courier: 'courier', service: 'service_giver' };

export function canOpen(appKey: string, role: EtelRole | undefined): boolean {
  if (OPEN_TO_ALL.includes(appKey)) return true;
  if (!role) return false;
  if (role === 'admin') return true;
  return ROLE_APP[appKey] === role;
}

export const ROLE_LABEL: Record<EtelRole, string> = {
  customer: 'Customer',
  driver: 'Driver',
  courier: 'Courier',
  service_giver: 'Service giver',
  admin: 'Admin',
};

// Roles that work on the road and can go online.
export function canGoOnline(role: EtelRole | undefined): boolean {
  return role === 'driver' || role === 'courier' || role === 'service_giver';
}

const ALL_KEYS = ['etel_token', 'etel_user', 'etel_courier', 'etel_service_giver', 'etel_sso'];

// Runs inside each web app before it loads: puts the hub login where the web app looks for it, or clears the old
// one after the hub signed out.
export function sessionJs(s: HubSession | null): string {
  if (!s) {
    return `(function(){try{${ALL_KEYS.map((k) => `localStorage.removeItem('${k}');`).join('')}}catch(e){}})();true;`;
  }
  const user = JSON.stringify({ id: s.id, name: s.name, phone: s.phone, role: s.role });
  const sso = s.ssoToken ? JSON.stringify({ token: s.ssoToken, etel_id: s.etelId }) : '';
  return (
    `(function(){try{` +
    `localStorage.setItem('etel_token',${JSON.stringify(s.token)});` +
    `localStorage.setItem('etel_user',${JSON.stringify(user)});` +
    (sso ? `localStorage.setItem('etel_sso',${JSON.stringify(sso)});` : '') +
    `}catch(e){}})();true;`
  );
}

export function useSession(): HubSession | null {
  const [s, setS] = useState<HubSession | null>(current);
  useEffect(() => {
    loadSession().then(setS);
    return subscribeSession(setS);
  }, []);
  return s;
}
