// Low-data mode: the rules, with no React or phone modules so they can be tested alone.
// While it is ON the app asks the server less often (checks are 2.5 times further apart, never more than 30 seconds apart) and skips decorative pictures.
export const LOW_DATA_FACTOR = 2.5;
export const LOW_DATA_MAX_MS = 30000;

// The gap between two checks. A short gap becomes longer; a gap that is already long is left alone; a bad number stays as it is.
export function scaleInterval(ms: number, on: boolean): number {
  if (!on || !(ms > 0)) return ms;
  if (ms >= LOW_DATA_MAX_MS) return ms;
  return Math.min(LOW_DATA_MAX_MS, Math.round(ms * LOW_DATA_FACTOR));
}

// How much less often, as a plain number for the screen ("about 2.5 times less").
export function savingText(ms: number): string {
  const was = scaleInterval(ms, false);
  const now = scaleInterval(ms, true);
  return was > 0 && now > was ? Math.round(was / 1000) + ' s -> ' + Math.round(now / 1000) + ' s' : '';
}

// What is stored for the switch: '1' = on. Anything else (nothing stored, a bad value) = off.
export const parseStored = (v: string | null | undefined): boolean => v === '1';
export const toStored = (on: boolean): string => (on ? '1' : '0');
