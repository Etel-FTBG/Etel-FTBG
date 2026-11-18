// Pure rules for the maintenance page and the "update the app" page (no React, so they can be tested alone).
export interface AppStatus {
  maintenance: { on: boolean; text_en: string; text_am: string };
  update: { required: boolean; min: string; text_en: string; text_am: string; url: string };
}

export type Blocker = { kind: 'maintenance'; text: string } | { kind: 'update'; text: string; url: string; min: string } | null;

// Only the Play Store, Expo's download pages and our own website may be opened from the update button.
export function updateUrl(u: string | undefined): string {
  const m = /^https:\/\/([^/:?#@\s]+)(\/[^\s"'<>]*)?$/i.exec((u || '').trim());
  if (!m) return '';
  const h = m[1].toLowerCase().replace(/^www\./, '');
  return h === 'play.google.com' || h === 'expo.dev' || h.endsWith('.expo.dev') || h === 'ethelmarket.com' ? (u || '').trim() : '';
}

// Anything that is not a well-formed answer means "all clear": a broken answer must never lock people out.
export function cleanStatus(raw: unknown): AppStatus | null {
  const r = raw as any;
  if (!r || typeof r !== 'object' || !r.maintenance || !r.update) return null;
  const s = (v: unknown) => (typeof v === 'string' ? v : '');
  return {
    maintenance: { on: r.maintenance.on === true, text_en: s(r.maintenance.text_en), text_am: s(r.maintenance.text_am) },
    update: { required: r.update.required === true, min: s(r.update.min), text_en: s(r.update.text_en), text_am: s(r.update.text_am), url: s(r.update.url) },
  };
}

const MAINT_DEFAULT = { en: 'ETEL is being improved and will be back soon. Please try again in a little while.', am: 'ኢትኤል እየተሻሻለ ነው፣ በቅርቡ ይመለሳል። እባክዎ ትንሽ ቆይተው ይሞክሩ።' };
const UPDATE_DEFAULT = { en: 'A newer version of ETEL is needed. Please update to keep using it.', am: 'አዲስ የኢትኤል ስሪት ያስፈልጋል። መጠቀም ለመቀጠል እባክዎ ያዘምኑ።' };

// What to show, if anything. Admin accounts are never blocked by maintenance (so the owner can test), but an old app is blocked for everyone.
export function decide(status: AppStatus | null, role: string | undefined, lang: 'en' | 'am'): Blocker {
  if (!status) return null;
  const pick = (en: string, am: string, d: { en: string; am: string }) => (lang === 'am' ? am || en || d.am : en || am || d.en);
  if (status.update.required) {
    return { kind: 'update', text: pick(status.update.text_en, status.update.text_am, UPDATE_DEFAULT), url: updateUrl(status.update.url), min: status.update.min };
  }
  if (status.maintenance.on && role !== 'admin') {
    return { kind: 'maintenance', text: pick(status.maintenance.text_en, status.maintenance.text_am, MAINT_DEFAULT) };
  }
  return null;
}
