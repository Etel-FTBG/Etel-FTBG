// The Ethiopian calendar (the one used in Ethiopia: 13 months, 12 of 30 days and Pagume of 5 or 6 days, about 7-8 years behind the Gregorian one).
// Pure functions, no phone modules, so they can be tested alone. Dates are plain { y, m, d } numbers; m is 1-13.

export interface YMD {
  y: number;
  m: number;
  d: number;
}

// English spellings people see on signs and in news, and the Amharic names.
export const MONTHS_EN = ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'];
export const MONTHS_AM = ['መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜን'];
// Weekday names, Monday first (the weekday of a day is the same in both calendars).
export const WEEKDAYS_AM = ['ሰኞ', 'ማክሰኞ', 'ረቡዕ', 'ሐሙስ', 'አርብ', 'ቅዳሜ', 'እሁድ'];

const EPOCH = 1723856; // julian day number used by the standard Ethiopic conversion

export const isLeapEthiopic = (y: number): boolean => y % 4 === 3;
export function daysInEthiopicMonth(y: number, m: number): number {
  if (m >= 1 && m <= 12) return 30;
  if (m === 13) return isLeapEthiopic(y) ? 6 : 5;
  return 0;
}
export function isValidEthiopic(y: number, m: number, d: number): boolean {
  return Number.isInteger(y) && Number.isInteger(m) && Number.isInteger(d) && y >= 1 && y <= 9999 && m >= 1 && m <= 13 && d >= 1 && d <= daysInEthiopicMonth(y, m);
}

// Gregorian y-m-d -> julian day number
function gregorianToJdn(y: number, m: number, d: number): number {
  const a = Math.floor((14 - m) / 12);
  const yy = y + 4800 - a;
  const mm = m + 12 * a - 3;
  return d + Math.floor((153 * mm + 2) / 5) + 365 * yy + Math.floor(yy / 4) - Math.floor(yy / 100) + Math.floor(yy / 400) - 32045;
}
function jdnToGregorian(jdn: number): YMD {
  const a = jdn + 32044;
  const b = Math.floor((4 * a + 3) / 146097);
  const c = a - Math.floor((146097 * b) / 4);
  const d = Math.floor((4 * c + 3) / 1461);
  const e = c - Math.floor((1461 * d) / 4);
  const m = Math.floor((5 * e + 2) / 153);
  return { d: e - Math.floor((153 * m + 2) / 5) + 1, m: m + 3 - 12 * Math.floor(m / 10), y: 100 * b + d - 4800 + Math.floor(m / 10) };
}

export function gregorianToEthiopic(y: number, m: number, d: number): YMD {
  const jdn = gregorianToJdn(y, m, d);
  const r = (jdn - EPOCH) % 1461;
  const n = (r % 365) + 365 * Math.floor(r / 1460);
  return {
    y: 4 * Math.floor((jdn - EPOCH) / 1461) + Math.floor(r / 365) - Math.floor(r / 1460),
    m: Math.floor(n / 30) + 1,
    d: (n % 30) + 1,
  };
}

// null when the Ethiopian date does not exist (for example Pagume 6 in a year that is not a leap year, or Tir 31)
export function ethiopicToGregorian(y: number, m: number, d: number): YMD | null {
  if (!isValidEthiopic(y, m, d)) return null;
  return jdnToGregorian(EPOCH + 365 + 365 * (y - 1) + Math.floor(y / 4) + 30 * m + d - 31);
}
