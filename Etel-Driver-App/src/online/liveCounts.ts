import { boot, getToken } from '../api/client';

// What is going on for this account right now: live trips, new ride requests, new orders and deliveries in progress.
// One small server call (hub_counts). It feeds the golden light's counts while the user is outside the app.
export interface LiveCounts {
  trips: number;
  requests: number;
  orders: number;
  deliveries: number;
  total: number;
}

const ZERO: LiveCounts = { trips: 0, requests: 0, orders: 0, deliveries: 0, total: 0 };
const MIN_GAP_MS = 12000;

// A sample set the profile card can switch on, so the golden light can be seen working without a live trip or order.
const DEMO: LiveCounts = { trips: 1, requests: 2, orders: 1, deliveries: 1, total: 5 };

let counts: LiveCounts = ZERO;
let demo = false;
let lastFetch = 0;
let inflight = false;
const listeners = new Set<(c: LiveCounts) => void>();

function set(next: LiveCounts) {
  if (
    next.trips === counts.trips &&
    next.requests === counts.requests &&
    next.orders === counts.orders &&
    next.deliveries === counts.deliveries
  ) {
    return;
  }
  counts = next;
  listeners.forEach((l) => l(getCounts()));
}

export function getCounts(): LiveCounts {
  return demo ? DEMO : counts;
}

export function isDemo(): boolean {
  return demo;
}

export function setDemo(on: boolean): void {
  if (demo === on) return;
  demo = on;
  listeners.forEach((l) => l(getCounts()));
}

export function subscribeCounts(l: (c: LiveCounts) => void): () => void {
  listeners.add(l);
  return () => {
    listeners.delete(l);
  };
}

export function resetCounts(): void {
  lastFetch = 0;
  set(ZERO);
}

// Safe to call often (the background location tick calls it every few seconds): it only asks the server again after
// MIN_GAP_MS, and never overlaps itself.
export async function refreshCounts(force = false): Promise<LiveCounts> {
  if (inflight) return counts;
  if (!force && Date.now() - lastFetch < MIN_GAP_MS) return counts;
  if (!(await getToken())) {
    set(ZERO);
    return counts;
  }
  inflight = true;
  lastFetch = Date.now();
  try {
    const r = await boot<Partial<LiveCounts>>('hub_counts');
    const n = (v: unknown) => (typeof v === 'number' && v > 0 ? Math.floor(v) : 0);
    const next = { trips: n(r.trips), requests: n(r.requests), orders: n(r.orders), deliveries: n(r.deliveries), total: 0 };
    next.total = next.trips + next.requests + next.orders + next.deliveries;
    set(next);
  } catch {
    // offline or a hiccup: keep showing the last known counts
  } finally {
    inflight = false;
  }
  return counts;
}
