import { MONTHS_AM, MONTHS_EN, WEEKDAYS_AM, YMD, ethiopicToGregorian, gregorianToEthiopic, isValidEthiopic } from './ethiopic';

// How a date is written on screen, in either calendar. Gregorian output is exactly what the app showed before (so nothing changes until the
// person picks the Ethiopian calendar). Pure functions, no phone modules.
export type Calendar = 'gregorian' | 'ethiopian';
const G_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const G_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export const parseCalendar = (v: string | null | undefined): Calendar => (v === 'ethiopian' ? 'ethiopian' : 'gregorian');

// "2026-11-21" or "2026-11-21 09:30:00" -> { y, m, d, hm } (hm is "09:30" or ''); null when it is not a date.
export function parseServerDate(s: string | null | undefined): (YMD & { hm: string }) | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}))?/.exec(s || '');
  if (!m) return null;
  const y = +m[1], mo = +m[2], d = +m[3];
  if (mo < 1 || mo > 12 || d < 1 || d > 31) return null;
  return { y, m: mo, d, hm: m[4] || '' };
}

function monthName(cal: Calendar, m: number, am: boolean): string {
  return cal === 'ethiopian' ? (am ? MONTHS_AM : MONTHS_EN)[m - 1] : G_MONTHS[m - 1];
}
function convert(p: YMD, cal: Calendar): YMD {
  return cal === 'ethiopian' ? gregorianToEthiopic(p.y, p.m, p.d) : p;
}

// "16 Nov" / "12 Hidar" (Amharic: "ኅዳር 12")
export function fmtShort(s: string | null | undefined, cal: Calendar, am = false): string {
  const p = parseServerDate(s);
  if (!p) return s || '';
  const c = convert(p, cal);
  return cal === 'ethiopian' && am ? monthName(cal, c.m, true) + ' ' + c.d : c.d + ' ' + monthName(cal, c.m, am);
}

// "16 Nov 2026" / "12 Hidar 2019 EC" (Amharic: "ኅዳር 12 ቀን 2019 ዓ.ም")
export function fmtDate(s: string | null | undefined, cal: Calendar, am = false): string {
  const p = parseServerDate(s);
  if (!p) return s || '';
  const c = convert(p, cal);
  if (cal === 'ethiopian') return am ? monthName(cal, c.m, true) + ' ' + c.d + ' ቀን ' + c.y + ' ዓ.ም' : c.d + ' ' + monthName(cal, c.m, false) + ' ' + c.y + ' EC';
  return c.d + ' ' + monthName(cal, c.m, false) + ' ' + c.y;
}

// "16 Nov 09:30" / "12 Hidar 09:30" - the day and the time, without the year (for lists of recent things)
export function fmtDateTime(s: string | null | undefined, cal: Calendar, am = false): string {
  const p = parseServerDate(s);
  if (!p) return s || '';
  return fmtShort(s, cal, am) + (p.hm ? ' ' + p.hm : '');
}

// The weekday is the same in both calendars. "Mon" or, in Amharic, "ሰኞ".
export function weekdayName(s: string | null | undefined, am = false): string {
  const p = parseServerDate(s);
  if (!p) return '';
  const i = (new Date(Date.UTC(p.y, p.m - 1, p.d)).getUTCDay() + 6) % 7;
  return am ? WEEKDAYS_AM[i] : G_DAYS[i];
}

// A date the person typed as YYYY-MM-DD in the chosen calendar -> the Gregorian "YYYY-MM-DD" the server stores, or null when it is not a real date.
// Gregorian input is checked for real (so 2026-02-31 is refused); Ethiopian input also allows Pagume 5 or 6.
export function toServerDate(input: string, cal: Calendar): string | null {
  const m = /^\s*(\d{4})-(\d{1,2})-(\d{1,2})\s*$/.exec(input || '');
  if (!m) return null;
  const y = +m[1], mo = +m[2], d = +m[3];
  let g: YMD | null;
  if (cal === 'ethiopian') {
    g = isValidEthiopic(y, mo, d) ? ethiopicToGregorian(y, mo, d) : null;
  } else {
    const t = new Date(Date.UTC(y, mo - 1, d));
    g = t.getUTCFullYear() === y && t.getUTCMonth() === mo - 1 && t.getUTCDate() === d ? { y, m: mo, d } : null;
  }
  if (!g) return null;
  return String(g.y).padStart(4, '0') + '-' + String(g.m).padStart(2, '0') + '-' + String(g.d).padStart(2, '0');
}

// The same stored date written back in the chosen calendar as YYYY-MM-DD (for showing an existing date in the input box).
export function toInputDate(serverDate: string | null | undefined, cal: Calendar): string {
  const p = parseServerDate(serverDate);
  if (!p) return '';
  const c = convert(p, cal);
  return String(c.y).padStart(4, '0') + '-' + String(c.m).padStart(2, '0') + '-' + String(c.d).padStart(2, '0');
}

// A server time stamp for lists (Support, Notifications, Wallet). Gregorian keeps the old plain "2026-11-20 09:30"; Ethiopian shows "12 Hidar 2019 EC 09:30".
export function fmtStamp(s: string | null | undefined, cal: Calendar, am = false): string {
  if (typeof s !== 'string') return '';
  if (cal !== 'ethiopian') return s.substring(0, 16);
  const p = parseServerDate(s);
  return p ? fmtDate(s, cal, am) + (p.hm ? ' ' + p.hm : '') : s.substring(0, 16);
}
