import { boot } from './client';

// The Support desk (Admin > Support desk) from the phone: the same requests the web Help page and every app's Help button use.
export interface SupTicket {
  id: number;
  ref: string;
  app: string;
  category: string;
  subject: string;
  status: 'open' | 'pending' | 'solved' | 'closed' | string;
  unread: boolean;
  rating: number | null;
  created_at: string;
  updated_at: string;
}
export interface SupMessage {
  from: 'me' | 'support' | 'system';
  body: string;
  at: string;
}
export interface SupMeta {
  enabled: boolean;
  csat: boolean;
  unread: number;
  categories: string[];
}

export async function supMeta(): Promise<SupMeta> {
  const r = await boot<{ enabled?: boolean; csat?: boolean; unread?: number; categories?: string[] }>('sup_meta', {});
  return { enabled: r.enabled !== false, csat: r.csat === true, unread: typeof r.unread === 'number' ? r.unread : 0, categories: Array.isArray(r.categories) ? r.categories : [] };
}
export async function supMyTickets(): Promise<{ tickets: SupTicket[]; unread: number }> {
  const r = await boot<{ tickets?: SupTicket[]; unread?: number }>('sup_my_tickets', {});
  return { tickets: Array.isArray(r.tickets) ? r.tickets : [], unread: typeof r.unread === 'number' ? r.unread : 0 };
}
export async function supGet(id: number): Promise<{ ticket: SupTicket; messages: SupMessage[]; can_rate: boolean; can_reply: boolean }> {
  const r = await boot<any>('sup_ticket_get', { id });
  return { ticket: r.ticket, messages: Array.isArray(r.messages) ? r.messages : [], can_rate: r.can_rate === true, can_reply: r.can_reply !== false };
}
export async function supCreate(p: { app: string; category: string; subject: string; body: string; lang: 'en' | 'am' }): Promise<SupTicket> {
  const r = await boot<{ ticket: SupTicket }>('sup_ticket_create', p);
  return r.ticket;
}
export async function supReply(id: number, body: string): Promise<void> {
  await boot('sup_ticket_reply', { id, body });
}
export async function supClose(id: number): Promise<void> {
  await boot('sup_ticket_close', { id });
}
export async function supRate(id: number, rating: number): Promise<void> {
  await boot('sup_ticket_rate', { id, rating });
}
