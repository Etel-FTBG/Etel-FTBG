import type { Statement } from '../api/statement';

// Small helpers for the weekly statement screen (no React, so they can be tested alone).
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// 1234.5 -> "ETB 1,234.50"; anything that is not a number counts as 0.
export function money(n: unknown): string {
  const v = typeof n === 'number' && isFinite(n) ? n : parseFloat(String(n));
  const x = isFinite(v) ? v : 0;
  const neg = x < 0;
  const s = Math.abs(x).toFixed(2);
  const parts = s.split('.');
  return (neg ? '-' : '') + 'ETB ' + parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + parts[1];
}

// 0.05 -> "5%"
export function percent(rate: number): string {
  return Math.round((rate || 0) * 1000) / 10 + '%';
}

function parts(ymd: string): [number, number, number] | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd || '');
  return m ? [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)] : null;
}

// "2026-11-16" -> "16 Nov"
export function shortDate(ymd: string): string {
  const p = parts(ymd);
  return p ? p[2] + ' ' + MONTHS[p[1] - 1] : ymd || '';
}

// ("2026-11-16", "2026-11-22") -> "16 Nov - 22 Nov 2026"
export function weekLabel(start: string, end: string): string {
  const e = parts(end);
  return shortDate(start) + ' - ' + shortDate(end) + (e ? ' ' + e[0] : '');
}

// "2026-11-16" -> "Mon" (the statement week starts on Monday, but this works for any date)
export function dayName(ymd: string): string {
  const p = parts(ymd);
  if (!p) return '';
  const d = new Date(Date.UTC(p[0], p[1] - 1, p[2]));
  return DAYS[(d.getUTCDay() + 6) % 7];
}

// "2026-11-20 09:30:45" -> "20 Nov 09:30"
export function tripTime(at: string): string {
  const p = parts(at);
  const t = /\s(\d{2}:\d{2})/.exec(at || '');
  return p ? p[2] + ' ' + MONTHS[p[1] - 1] + (t ? ' ' + t[1] : '') : at || '';
}

export function payLabel(collectedBy: string): string {
  return collectedBy === 'etel' ? 'Paid through ETEL' : 'Cash';
}

// Height (0..1) of a day bar relative to the best day of the week; a day with nothing is 0.
export function barFraction(value: number, best: number): number {
  if (!(best > 0) || !(value > 0)) return 0;
  return Math.max(0.06, Math.min(1, value / best));
}

// The plain text a driver can send to someone (Share button): the week, the totals and one line per day.
export function shareText(st: Statement): string {
  const t = st.totals;
  const lines = [
    'ETEL weekly statement ' + weekLabel(st.week_start, st.week_end),
    'Trips: ' + t.trips,
    'Riders paid: ' + money(t.rider_paid),
    'Promo codes: -' + money(t.promo),
    'ETEL fee' + (st.commission_rate ? ' (' + percent(st.commission_rate) + ')' : '') + ': -' + money(t.commission),
    'You keep: ' + money(t.you_keep),
    'Tips: ' + money(st.tips),
    'Total with tips: ' + money(st.total_with_tips),
    'Hours online: ' + st.online_hours,
  ];
  if (st.per_hour != null) lines.push('Per hour: ' + money(st.per_hour));
  lines.push('');
  st.days.forEach((d) => lines.push(dayName(d.date) + ' ' + shortDate(d.date) + ': ' + d.trips + (d.trips === 1 ? ' trip, ' : ' trips, ') + money(d.you_keep)));
  return lines.join('\n');
}
