import { boot } from './client';

// "Delete my account" is a request, not an instant wipe: it lands in the Support inbox in Admin (same as the web apps).
// The team confirms it is really the owner, then deletes the account and keeps only what the law requires.
export async function requestAccountDeletion(who: { name?: string; phone?: string; role?: string }) {
  const bits = [who.name, who.phone, who.role].filter(Boolean).join(', ');
  return boot('customer_send_message', {
    subject: 'Account deletion request',
    body: 'I would like to permanently delete my ETEL app account and associated personal data.' + (bits ? ' (' + bits + ')' : ''),
  });
}
