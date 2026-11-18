import { useEffect, useState } from 'react';
import * as SecureStore from 'expo-secure-store';
import { boot } from '../api/client';
import { applyHubConfig, HubOverride } from './apps';

// Which apps the owner shows or hides (Admin > ETEL app). The last answer is kept on the phone, so the hub looks the same offline;
// when the check fails the old choice stays (an app is never hidden or shown just because the network failed).
const KEY = 'etel_hub_config';

async function readCache(): Promise<Record<string, HubOverride> | null> {
  try {
    const raw = await SecureStore.getItemAsync(KEY);
    return raw ? (JSON.parse(raw) as Record<string, HubOverride>) : null;
  } catch {
    return null;
  }
}

// Applies the last answer kept on the phone (no network). Used when the app starts in the background.
export async function loadHubConfigCache(): Promise<void> {
  const c = await readCache();
  if (c) applyHubConfig(c);
}

export async function refreshHubConfig(): Promise<boolean> {
  try {
    const r = await boot<{ apps?: Record<string, HubOverride> }>('get_hub_apps', {});
    const apps = r && typeof r.apps === 'object' && r.apps ? r.apps : {};
    applyHubConfig(apps);
    SecureStore.setItemAsync(KEY, JSON.stringify(apps)).catch(() => {});
    return true;
  } catch {
    return false;
  }
}

// Returns a number that changes whenever the choices were (re)loaded, so the hub draws again. Checked again when `refreshKey` changes.
export function useHubConfig(signedIn: boolean, refreshKey: number): number {
  const [v, setV] = useState(0);
  useEffect(() => {
    let live = true;
    readCache().then((c) => {
      if (live && c) {
        applyHubConfig(c);
        setV((n) => n + 1);
      }
    });
    if (signedIn) {
      refreshHubConfig().then((ok) => {
        if (live && ok) setV((n) => n + 1);
      });
    }
    return () => {
      live = false;
    };
  }, [signedIn, refreshKey]);
  return v;
}
