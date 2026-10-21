import { setOnlineState } from '../online/onlineState';
import { stopDriverLocationTracking, setCourierLocationEnabled } from '../location/backgroundLocationTask';
import { COMPANIES, companiesFor, markAllOffline, setCompanyOnline } from './companies';
import { getSession } from './session';
import { setDemo } from '../online/liveCounts';

// One online switch for everything the account works in: puts it online in each of its companies at once.
export async function goOnlineEverywhere(): Promise<string[]> {
  const list = companiesFor(getSession()?.role);
  if (list.length === 0) throw new Error('This account is not set up to go online yet.');
  const results = await Promise.allSettled(list.map((c) => setCompanyOnline(c.key, true)));
  const on: string[] = [];
  let firstError = '';
  results.forEach((r, i) => {
    if (r.status === 'fulfilled') on.push(list[i].title);
    else if (!firstError && r.reason instanceof Error) firstError = r.reason.message;
  });
  if (on.length === 0) throw new Error(firstError || 'This account is not set up to go online yet.');
  return on;
}

// An admin or customer account has no company to go online in. Sliding online there turns on a preview: the golden
// light shows with sample counts when the app is left, so it can be seen working.
export function goOnlinePreview(): void {
  setDemo(true);
  setOnlineState(true);
}

export async function goOfflineEverywhere(): Promise<void> {
  setDemo(false);
  await Promise.allSettled((Object.keys(COMPANIES) as (keyof typeof COMPANIES)[]).map((k) => COMPANIES[k].goOffline()));
  markAllOffline();
  setCourierLocationEnabled(false);
  await stopDriverLocationTracking().catch(() => {});
  setOnlineState(false);
}
