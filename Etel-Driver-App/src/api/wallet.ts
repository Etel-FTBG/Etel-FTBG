import { boot } from './client';

export type ComplianceDocType = 'insurance' | 'technical_inspection' | 'road_fund';

export interface ComplianceDocStatus {
  status: 'unknown' | 'valid' | 'expired';
  expiry_date: string | null;
  receipt_url: string;
  pending: { requested_expiry_date: string; created_at: string } | null;
}

// RideController.php:2138
export async function getComplianceStatus(): Promise<{
  documents: Record<ComplianceDocType, ComplianceDocStatus>;
}> {
  return boot('driver_get_compliance_status', {});
}

// RideController.php:2190
export async function submitComplianceDocument(
  docType: ComplianceDocType,
  expiryDate: string,
  receiptUrl: string
) {
  return boot('driver_submit_compliance_document', {
    doc_type: docType,
    expiry_date: expiryDate,
    receipt_url: receiptUrl,
  });
}

// RideController.php:2651
export async function cashout(amount: number) {
  return boot<{ tx_ref: string; amount: number; available_balance: number }>('driver_cashout', { amount });
}

// RideController.php:2818
export async function submitManualTopup(amount: number, receiptImageUrl: string, note?: string) {
  return boot('driver_submit_manual_topup', {
    amount,
    receipt_image_url: receiptImageUrl,
    ...(note ? { note } : {}),
  });
}

export interface TopupRecord {
  id: number;
  amount: number;
  tx_ref: string;
  source: string;
  status: string;
  note: string | null;
  receipt_image_url: string | null;
  created_at: string;
  approved_at: string | null;
}

// RideController.php:2879
export async function getTopupHistory(): Promise<{ topups: TopupRecord[] }> {
  return boot('driver_get_topup_history', {});
}
