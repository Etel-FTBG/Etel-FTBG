// The API returns lat/lng from DECIMAL columns, which PHP/PDO serialises as
// strings, and they are nullable. react-native-maps' Android code reads these
// with getDouble() and throws NoSuchKeyException (killing the app) when a
// value is null or was dropped by the bridge as undefined.
export function toNum(v: unknown): number | null {
  if (v === null || v === undefined || v === '') return null;
  const n = typeof v === 'number' ? v : Number(v);
  return Number.isFinite(n) ? n : null;
}

export function isValidCoord(lat?: number | null, lng?: number | null): lat is number {
  return typeof lat === 'number' && typeof lng === 'number' && Number.isFinite(lat) && Number.isFinite(lng);
}

export function formatCoord(lat?: number | null, lng?: number | null): string {
  return isValidCoord(lat, lng) ? `${lat.toFixed(4)}, ${(lng as number).toFixed(4)}` : '—';
}
