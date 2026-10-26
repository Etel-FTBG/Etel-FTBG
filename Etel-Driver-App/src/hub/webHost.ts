import { useEffect, useState } from 'react';
import { HUB_APPS } from './apps';
import { EtelRole } from './session';

// The web apps live in one place (WebHost, mounted once above the navigator) instead of inside the screen, so an app that was
// preloaded or opened before is still alive when you open it again, and opens at once.
export interface PageState {
  loading: boolean;
  failed: boolean;
  canGoBack: boolean;
}
interface Host {
  opened: string[]; // oldest first; at most MAX are kept alive
  active: string | null; // the app the WebApp screen is showing right now
  top: number; // where the page starts, below the screen's bar
  pages: Record<string, PageState>;
}

const MAX = 3;
const fresh = (): PageState => ({ loading: true, failed: false, canGoBack: false });
let host: Host = { opened: [], active: null, top: 0, pages: {} };
const listeners = new Set<(h: Host) => void>();
const handles: Record<string, { goBack(): void; reload(): void } | null> = {};

function set(next: Host) {
  host = next;
  listeners.forEach((l) => l(host));
}

export function useWebHost(): Host {
  const [h, setH] = useState(host);
  useEffect(() => {
    listeners.add(setH);
    setH(host);
    return () => {
      listeners.delete(setH);
    };
  }, []);
  return h;
}

export function registerHandle(key: string, h: { goBack(): void; reload(): void } | null) {
  handles[key] = h;
}
export function hostGoBack(key: string) {
  handles[key]?.goBack();
}
export function hostReload(key: string) {
  patchPage(key, { loading: true, failed: false });
  handles[key]?.reload();
}

export function patchPage(key: string, p: Partial<PageState>) {
  const cur = host.pages[key] || fresh();
  set({ ...host, pages: { ...host.pages, [key]: { ...cur, ...p } } });
}

// Makes sure an app is mounted (hidden) so it starts loading; the oldest idle one is dropped past MAX.
export function warmApp(key: string) {
  if (host.opened.includes(key)) return;
  let opened = [...host.opened, key];
  while (opened.length > MAX) {
    const drop = opened.find((k) => k !== host.active && k !== key);
    if (!drop) break;
    opened = opened.filter((k) => k !== drop);
  }
  set({ ...host, opened, pages: { ...host.pages, [key]: fresh() } });
}

export function activateApp(key: string, top: number) {
  warmApp(key);
  set({ ...host, active: key, top });
}
export function deactivateApp(key: string) {
  if (host.active === key) set({ ...host, active: null });
}

// Sign-out or a different account: nothing may stay alive with the old login.
export function resetHost() {
  set({ opened: [], active: host.active, top: host.top, pages: {} });
}

// The app each role lands in most often; it is loaded in the background right after sign-in.
export function primaryAppFor(role: EtelRole | undefined): string | null {
  if (role === 'driver') return 'driver';
  if (role === 'courier') return 'courier';
  if (role === 'service_giver') return 'service';
  if (role === 'customer') return 'ride';
  return null;
}
export const knownApp = (key: string) => HUB_APPS.some((a) => a.key === key && a.status === 'live');
