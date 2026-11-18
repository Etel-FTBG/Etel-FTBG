import { useEffect, useState } from 'react';
import * as SecureStore from 'expo-secure-store';
import { boot } from '../api/client';
import { applyHubConfig, HubOverride } from './apps';
import { applyHubContent, AppContent, HubBannerData } from './hubContent';

// Which apps the owner shows or hides (Admin > ETEL app). The last answer is kept on the phone, so the hub looks the same offline;
// when the check fails the old choice stays (an app is never hidden or shown just because the network failed).
const KEY = 'etel_hub_config';

interface Payload {
  apps: Record<string, HubOverride>;
  content?: Record<string, AppContent>;
  texts?: Record<string, string>;
  banner?: HubBannerData | null;
}

function applyAll(p: Payload) {
  applyHubConfig(p.apps || {});
  applyHubContent(p.content, p.texts, p.banner);
}

async function readCache(): Promise<Payload | null> {
  try {
    const raw = await SecureStore.getItemAsync(KEY);
    if (!raw) return null;
    const j = JSON.parse(raw);
    // The first version kept only the switches (a plain map); newer ones keep { apps, content, texts, banner }.
    return j && typeof j === 'object' && j.apps && typeof j.apps === 'object' ? (j as Payload) : { apps: j as Record<string, HubOverride> };
  } catch {
    return null;
  }
}

// Applies the last answer kept on the phone (no network). Used when the app starts in the background.
export async function loadHubConfigCache(): Promise<void> {
  const c = await readCache();
  if (c) applyAll(c);
}

export async function refreshHubConfig(): Promise<boolean> {
  try {
    const r = await boot<Partial<Payload>>('get_hub_apps', {});
    const p: Payload = {
      apps: r && typeof r.apps === 'object' && r.apps ? r.apps : {},
      content: r && typeof r.content === 'object' && r.content ? r.content : {},
      texts: r && typeof r.texts === 'object' && r.texts ? r.texts : {},
      banner: r && r.banner ? r.banner : null,
    };
    applyAll(p);
    SecureStore.setItemAsync(KEY, JSON.stringify(p)).catch(() => {});
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
        applyAll(c);
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
