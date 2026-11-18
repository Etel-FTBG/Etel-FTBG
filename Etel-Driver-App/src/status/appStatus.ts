import { useEffect, useRef, useState } from 'react';
import { AppState } from 'react-native';
import Constants from 'expo-constants';
import { boot } from '../api/client';
import { AppStatus, cleanStatus } from './statusLogic';

// The app's own version (app.base.json "version"). Sent so the server can say whether it is too old.
export const APP_VERSION: string = (Constants.expoConfig?.version as string | undefined) || '';

// Asks the server, signed in or not. Any failure (no network, server error, odd answer) returns null = "all clear", so a bad moment never blocks anybody.
export async function fetchAppStatus(): Promise<AppStatus | null> {
  try {
    const r = await boot('app_status', { version: APP_VERSION }, { auth: false });
    return cleanStatus(r);
  } catch {
    return null;
  }
}

const RECHECK_MS = 30000;

// Checks when the app opens, each time it comes back to the front (at most every 30 seconds), and every 30 seconds while a page is showing
// (so the maintenance page goes away by itself when the owner switches it off).
export function useAppStatus(blocked: boolean): AppStatus | null {
  const [status, setStatus] = useState<AppStatus | null>(null);
  const last = useRef(0);
  const run = useRef<() => void>(() => {});
  run.current = () => {
    last.current = Date.now();
    fetchAppStatus().then((s) => setStatus(s));
  };
  useEffect(() => {
    run.current();
    const sub = AppState.addEventListener('change', (st) => {
      if (st === 'active' && Date.now() - last.current > RECHECK_MS) run.current();
    });
    return () => sub.remove();
  }, []);
  useEffect(() => {
    if (!blocked) return;
    const id = setInterval(() => run.current(), RECHECK_MS);
    return () => clearInterval(id);
  }, [blocked]);
  return status;
}
