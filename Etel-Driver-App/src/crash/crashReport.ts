import { Platform } from 'react-native';
import Constants from 'expo-constants';
import { API_BASE_URL } from '../api/client';
import { getSession } from '../hub/session';
import { CrashKind, buildReport, createLimiter } from './crashLogic';

// Sends a small note to the server (Admin > System health > App and browser errors) when the app breaks. Never throws, never waits for the answer,
// and stays quiet when the phone is offline (the note is simply dropped: it is a hint, not a record).
const ENDPOINT = API_BASE_URL.replace(/boot\.php$/, 'client-error.php');
const allow = createLimiter();
let installed = false;

export function reportCrash(kind: CrashKind, err: unknown, where: string): void {
  try {
    const r = buildReport(kind, err, where, {
      version: String(Constants.expoConfig?.version || '?'),
      os: Platform.OS,
      osVersion: Platform.Version,
      uid: getSession()?.id || 0,
    });
    if (!r || !allow(r)) return;
    const ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timer = ctl ? setTimeout(() => ctl.abort(), 8000) : null;
    fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(r), signal: ctl ? ctl.signal : undefined })
      .catch(() => {})
      .finally(() => {
        if (timer) clearTimeout(timer);
      });
  } catch {
    // reporting a crash must never cause one
  }
}

// Call once at start. Keeps the app's normal behaviour: the previous handler still runs after the note is queued.
export function installCrashReporting(): void {
  if (installed) return;
  installed = true;
  try {
    const eu = (globalThis as any).ErrorUtils;
    if (eu && typeof eu.setGlobalHandler === 'function') {
      const prev = typeof eu.getGlobalHandler === 'function' ? eu.getGlobalHandler() : null;
      eu.setGlobalHandler((error: unknown, isFatal?: boolean) => {
        reportCrash('js', error, isFatal ? 'fatal' : 'error');
        if (prev) prev(error, isFatal);
      });
    }
  } catch {
    // no handler available: the screen-level report still works
  }
  try {
    const h = (globalThis as any).HermesInternal;
    if (h && typeof h.enablePromiseRejectionTracker === 'function') {
      h.enablePromiseRejectionTracker({ allRejections: true, onUnhandled: (_id: number, error: unknown) => reportCrash('promise', error, 'promise') });
    }
  } catch {
    // older engine: skipped
  }
}
