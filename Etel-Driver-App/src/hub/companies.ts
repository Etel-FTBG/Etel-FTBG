import { useEffect, useState } from 'react';
import * as Location from 'expo-location';
import * as SecureStore from 'expo-secure-store';
import { boot, getToken } from '../api/client';
import {
  startDriverLocationTracking,
  stopDriverLocationTracking,
  setCourierLocationEnabled,
} from '../location/backgroundLocationTask';
import { setOnlineState } from '../online/onlineState';
import { LiveCounts } from '../online/liveCounts';
import { EtelRole } from './session';

// Each company the account works in has its own Online switch and its own Alerts switch. Online puts the account on
// that company's list (and keeps location running); Alerts decides whether its new requests, orders, trips and
// deliveries are counted on the golden light. Today an EtelID has one role, so most accounts have one company here.
export type CompanyKey = 'ride' | 'delivery' | 'services';

export interface Company {
  key: CompanyKey;
  title: string;
  subtitle: string;
  logo: number;
  needsLocation: boolean;
  goOnline: (lat: number, lng: number) => Promise<unknown>;
  goOffline: () => Promise<unknown>;
}

export const COMPANIES: Record<CompanyKey, Company> = {
  ride: {
    key: 'ride',
    title: 'ETEL Driver',
    subtitle: 'Ride requests',
    logo: require('../../assets/apps/driver.png'),
    needsLocation: true,
    goOnline: (lat, lng) => boot('driver_go_online', { lat, lng }),
    goOffline: () => boot('driver_go_offline', {}),
  },
  delivery: {
    key: 'delivery',
    title: 'ETEL Courier',
    subtitle: 'Delivery orders',
    logo: require('../../assets/apps/courier.png'),
    needsLocation: true,
    goOnline: (lat, lng) => boot('courier_go_online', { lat, lng }),
    goOffline: () => boot('courier_go_offline', {}),
  },
  services: {
    key: 'services',
    title: 'ETEL Services',
    subtitle: 'Service orders',
    logo: require('../../assets/apps/service.png'),
    needsLocation: false,
    goOnline: () => boot('sg_set_online', { is_online: 1 }),
    goOffline: () => boot('sg_set_online', { is_online: 0 }),
  },
};

const ROLE_COMPANIES: Record<string, CompanyKey[]> = {
  driver: ['ride'],
  courier: ['delivery'],
  service_giver: ['services'],
};

export function companiesFor(role: EtelRole | undefined): Company[] {
  return (role ? ROLE_COMPANIES[role] || [] : []).map((k) => COMPANIES[k]);
}

interface State {
  online: Record<CompanyKey, boolean>;
  alerts: Record<CompanyKey, boolean>;
}

let state: State = {
  online: { ride: false, delivery: false, services: false },
  alerts: { ride: true, delivery: true, services: true },
};
const listeners = new Set<(s: State) => void>();
const alertsKey = (k: CompanyKey) => 'etel_alerts_' + k;

function emit() {
  listeners.forEach((l) => l(state));
}

export function getCompanyState(): State {
  return state;
}

export function useCompanyState(): State {
  const [s, setS] = useState(state);
  useEffect(() => {
    setS(state);
    listeners.add(setS);
    return () => {
      listeners.delete(setS);
    };
  }, []);
  return s;
}

let alertsLoaded = false;
export async function loadAlertPrefs(): Promise<void> {
  if (alertsLoaded) return;
  alertsLoaded = true;
  const keys = Object.keys(COMPANIES) as CompanyKey[];
  const vals = await Promise.all(keys.map((k) => SecureStore.getItemAsync(alertsKey(k)).catch(() => null)));
  const alerts = { ...state.alerts };
  keys.forEach((k, i) => {
    if (vals[i] === '0') alerts[k] = false;
  });
  state = { ...state, alerts };
  emit();
}

export async function setAlerts(key: CompanyKey, on: boolean): Promise<void> {
  state = { ...state, alerts: { ...state.alerts, [key]: on } };
  emit();
  await SecureStore.setItemAsync(alertsKey(key), on ? '1' : '0').catch(() => {});
}

function anyOnline(): boolean {
  return state.online.ride || state.online.delivery || state.online.services;
}

// Puts the account online or offline in one company, and keeps location and the golden light in step.
export async function setCompanyOnline(key: CompanyKey, on: boolean): Promise<void> {
  const company = COMPANIES[key];
  if (on) {
    if (!(await getToken())) throw new Error('Sign in with your EtelID first.');
    let lat = 0;
    let lng = 0;
    if (company.needsLocation) {
      const fg = await Location.requestForegroundPermissionsAsync();
      if (fg.status !== 'granted') throw new Error('ETEL needs your location to go online.');
      const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      lat = pos.coords.latitude;
      lng = pos.coords.longitude;
    }
    await company.goOnline(lat, lng);
    state = { ...state, online: { ...state.online, [key]: true } };
    emit();
    if (key === 'delivery') setCourierLocationEnabled(true);
    try {
      await startDriverLocationTracking(); // keeps location running while the app is in the background
    } catch {
      // background permission refused: still online while the app is open
    }
    setOnlineState(true);
  } else {
    await company.goOffline().catch(() => {});
    state = { ...state, online: { ...state.online, [key]: false } };
    emit();
    if (key === 'delivery') setCourierLocationEnabled(false);
    if (!anyOnline()) {
      await stopDriverLocationTracking().catch(() => {});
      setOnlineState(false);
    }
  }
}

// The web Driver app inside the hub went online or offline by itself (it already told the server).
export function markCompanyOnline(key: CompanyKey, on: boolean): void {
  state = { ...state, online: { ...state.online, [key]: on } };
  emit();
}

export function markAllOffline(): void {
  state = { ...state, online: { ride: false, delivery: false, services: false } };
  emit();
}

// What the golden light should count for this account: new requests and orders only while online in that company,
// and nothing for a company whose Alerts switch is off.
export function applyAlertPrefs(c: LiveCounts, role: EtelRole | undefined): LiveCounts {
  const keys = role ? ROLE_COMPANIES[role] : undefined;
  if (!keys) return c;
  const key = keys[0];
  const alerts = state.alerts[key];
  const online = state.online[key];
  let { trips, requests, orders, deliveries } = c;
  if (!alerts) {
    trips = 0;
    requests = 0;
    orders = 0;
    deliveries = 0;
  } else if (!online) {
    requests = 0;
    orders = 0;
  }
  return { trips, requests, orders, deliveries, total: trips + requests + orders + deliveries };
}
