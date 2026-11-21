import type { Statement } from '../api/statement';
import { Calendar, fmtDate, fmtDateTime, fmtShort, parseServerDate, weekdayName } from '../calendar/dateFormat';

// Small helpers for the weekly statement screen (no React, so they can be tested alone).

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

// "2026-11-16" -> "16 Nov" (Ethiopian: "7 Hidar"; Amharic: "ኅዳር 7")
export function shortDate(ymd: string, cal: Calendar = 'gregorian', am = false): string {
  return fmtShort(ymd, cal, am);
}

// ("2026-11-16", "2026-11-22") -> "16 Nov - 22 Nov 2026" (Ethiopian: "7 Hidar - 13 Hidar 2019 EC"). The year is the end date's.
export function weekLabel(start: string, end: string, cal: Calendar = 'gregorian', am = false): string {
  const e = parseServerDate(end);
  if (!e) return shortDate(start, cal, am) + ' - ' + shortDate(end, cal, am);
  const full = fmtDate(end, cal, am);
  const year = full.substring(shortDate(end, cal, am).length);   // the part after "22 Nov": " 2026" or " 2019 EC"
  return shortDate(start, cal, am) + ' - ' + shortDate(end, cal, am) + year;
}

// "2026-11-16" -> "Mon" (Amharic: "ሰኞ"); the weekday is the same in both calendars
export function dayName(ymd: string, am = false): string {
  return weekdayName(ymd, am);
}

// "2026-11-20 09:30:45" -> "20 Nov 09:30" (Ethiopian: "11 Hidar 09:30")
export function tripTime(at: string, cal: Calendar = 'gregorian', am = false): string {
  return fmtDateTime(at, cal, am);
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
export function shareText(st: Statement, cal: Calendar = 'gregorian', am = false): string {
  const t = st.totals;
  const lines = [
    'ETEL weekly statement ' + weekLabel(st.week_start, st.week_end, cal, am),
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
  st.days.forEach((d) => lines.push(dayName(d.date, am) + ' ' + shortDate(d.date, cal, am) + ': ' + d.trips + (d.trips === 1 ? ' trip, ' : ' trips, ') + money(d.you_keep)));
  return lines.join('\n');
}
