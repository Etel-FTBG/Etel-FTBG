import { boot } from './client';

// The driver's weekly statement (api action driver_get_statement, the same one the web driver page uses): every trip with Etel's share shown.
export interface StatementTrip {
  id: number;
  completed_at: string;
  pickup: string | null;
  dropoff: string | null;
  payment_method: string | null;
  promo_code: string | null;
  rider_paid: number;
  promo: number;
  gross: number;
  commission: number;
  you_keep: number;
  collected_by: 'cash' | 'etel' | string;
}
export interface StatementDay {
  date: string;
  trips: number;
  gross: number;
  you_keep: number;
}
export interface Statement {
  week_start: string;
  week_end: string;
  commission_rate: number | null;
  totals: { trips: number; rider_paid: number; promo: number; gross: number; commission: number; you_keep: number; cash_collected: number; etel_collected: number; km: number };
  tips: number;
  total_with_tips: number;
  online_hours: number;
  per_hour: number | null;
  days: StatementDay[];
  trips: StatementTrip[];
  prev_week: string | null;
  next_week: string | null;
}

export async function getStatement(weekStart?: string): Promise<Statement> {
  return boot<Statement>('driver_get_statement', weekStart ? { week_start: weekStart } : {});
}
