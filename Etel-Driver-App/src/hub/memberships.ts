import { useEffect, useState } from 'react';
import * as SecureStore from 'expo-secure-store';
import { boot } from '../api/client';

// Programmes an account can belong to besides its working role. The hub shows an app for each one only to its members,
// so an investor, an Equb member or a field agent finds their app on the first screen after signing in.
export interface Memberships {
  investor: boolean;
  equb: boolean;
  agent: boolean;
}
export const NO_MEMBERSHIPS: Memberships = { investor: false, equb: false, agent: false };

const cacheKey = (id: number) => 'etel_memberships_' + id;

async function readCache(id: number): Promise<Memberships> {
  try {
    const raw = await SecureStore.getItemAsync(cacheKey(id));
    return raw ? { ...NO_MEMBERSHIPS, ...JSON.parse(raw) } : NO_MEMBERSHIPS;
  } catch {
    return NO_MEMBERSHIPS;
  }
}

// null = the check itself failed (offline, server error): keep what was known before instead of hiding the app.
async function ask(action: string, pick: (r: any) => boolean): Promise<boolean | null> {
  try {
    return pick(await boot(action, {}));
  } catch {
    return null;
  }
}

export async function fetchMemberships(userId: number): Promise<Memberships> {
  const [investor, equb, agent] = await Promise.all([
    ask('investor_portal_am_i_investor', (r) => !!r?.is_investor),
    ask('equb_am_i_permitted', (r) => !!r?.is_permitted),
    ask('fa_agent_me', (r) => !!r?.is_agent),
  ]);
  const old = await readCache(userId);
  const m: Memberships = { investor: investor ?? old.investor, equb: equb ?? old.equb, agent: agent ?? old.agent };
  SecureStore.setItemAsync(cacheKey(userId), JSON.stringify(m)).catch(() => {});
  return m;
}

// Checked again whenever `refreshKey` changes (pull to refresh).
export function useMemberships(userId: number | undefined, refreshKey: number): Memberships {
  const [m, setM] = useState<Memberships>(NO_MEMBERSHIPS);
  useEffect(() => {
    let live = true;
    if (!userId) {
      setM(NO_MEMBERSHIPS);
      return;
    }
    readCache(userId).then((c) => live && setM(c)); // last known answer shows at once
    fetchMemberships(userId).then((n) => live && setM(n));
    return () => {
      live = false;
    };
  }, [userId, refreshKey]);
  return m;
}
