import type { Device } from '../api/devices';

// Small helpers for the "Signed-in devices" screen (no React, so they can be tested alone).

// Server times look like "2026-11-21 14:05:09" (the server's clock). Both the time and "now" come from the server, so the phone's clock is not used.
// Returns "now", "5 min ago", "3 h ago", "2 days ago" or the date for anything older than 8 days; '' when a time is missing.
function wall(s: string | null | undefined): number | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || '');
  return m ? Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : null;
}
export function ago(at: string | null | undefined, now: string | null | undefined, am = false): string {
  const t = wall(at);
  const n = wall(now);
  if (t === null || n === null) return t === null ? '' : (at || '').substring(0, 10);
  const mins = Math.floor((n - t) / 60000);
  if (mins < 2) return am ? 'አሁን' : 'now';
  if (mins < 60) return am ? 'ከ' + mins + ' ደቂቃ በፊት' : mins + ' min ago';
  const h = Math.floor(mins / 60);
  if (h < 24) return am ? 'ከ' + h + ' ሰዓት በፊት' : h + ' h ago';
  const d = Math.floor(h / 24);
  if (d < 8) return am ? 'ከ' + d + ' ቀን በፊት' : d + (d === 1 ? ' day ago' : ' days ago');
  return (at || '').substring(0, 10);
}

// This device first, then the others by most recent activity (the server already sorts them; this keeps the current one on top).
export function ordered(list: Device[]): Device[] {
  return list.slice().sort((a, b) => (a.current === b.current ? 0 : a.current ? -1 : 1));
}

export function othersCount(list: Device[]): number {
  return list.filter((d) => !d.current).length;
}

export function deviceIcon(label: string): 'phone-portrait-outline' | 'desktop-outline' | 'globe-outline' {
  if (/phone app|Android|iPhone/i.test(label)) return 'phone-portrait-outline';
  if (/Windows|Mac|Linux/i.test(label)) return 'desktop-outline';
  return 'globe-outline';
}
