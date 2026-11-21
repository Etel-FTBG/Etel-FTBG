// Small rules for the in-app support screens (no React, so they can be tested alone). They mirror what the server checks, so a person is told before sending.
export const SUPPORT_CATEGORIES: { key: string; label: string }[] = [
  { key: 'payment', label: 'Payment' },
  { key: 'account', label: 'Account' },
  { key: 'trip', label: 'Trip' },
  { key: 'order', label: 'Order' },
  { key: 'safety', label: 'Safety' },
  { key: 'app', label: 'The app' },
  { key: 'other', label: 'Other' },
];

// Which app of the Support desk a request is filed under, from the signed-in role.
export function appForRole(role: string | undefined): string {
  if (role === 'driver') return 'driver';
  if (role === 'courier') return 'courier';
  if (role === 'service_giver') return 'service';
  return 'other';
}

const len = (s: string) => Array.from(s.trim()).length;

// '' when fine, else the sentence to show (same limits as the server: a title of 3+ letters, a message of 5+, at most 160 and 4000).
export function validateRequest(subject: string, body: string): string {
  if (len(subject) < 3) return 'Please write a short title (at least 3 letters).';
  if (len(subject) > 160) return 'The title is too long (160 letters at most).';
  if (len(body) < 5) return 'Please tell us what happened (at least 5 letters).';
  if (len(body) > 4000) return 'The message is too long (4000 letters at most).';
  return '';
}
export function validateReply(body: string): string {
  if (len(body) < 2) return 'Please write your message.';
  if (len(body) > 4000) return 'The message is too long (4000 letters at most).';
  return '';
}

export function statusLabel(status: string): string {
  if (status === 'open') return 'Waiting for support';
  if (status === 'pending') return 'Support replied';
  if (status === 'solved') return 'Solved';
  if (status === 'closed') return 'Closed';
  return status;
}
export const canMarkSolved = (status: string) => status === 'open' || status === 'pending';
export const unreadText = (n: number) => (n > 0 ? ' (' + n + ' new)' : '');

// Server time stamps look like "2026-11-20 09:30:00": show "2026-11-20 09:30".
export function shortTime(t: string): string {
  return typeof t === 'string' ? t.substring(0, 16) : '';
}
