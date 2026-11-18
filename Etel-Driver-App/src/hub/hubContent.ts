import { HubApp, safeUrl } from './apps';

// How the owner changed the look of the hub (Admin > ETEL app > Names, icons and texts): names, short descriptions, labels, order and icons of
// the apps, the welcome title, tagline, support contacts and the home banner. An app or text with no change keeps its built-in value.
// A picture address from the server is used only when it is on this site's own host and in the hub upload folder (the server checks too).
export interface AppContent {
  title_en?: string;
  title_am?: string;
  desc_en?: string;
  desc_am?: string;
  badge?: string;
  sort_order?: number;
  icon_url?: string;
}
export interface HubBannerData {
  id: string;
  title_en: string;
  title_am: string;
  text_en: string;
  text_am: string;
  image_url: string;
  link_app: string;
}

let content: Record<string, AppContent> = {};
let texts: Record<string, string> = {};
let banner: HubBannerData | null = null;

const hubPicture = (u: string | undefined): string => {
  const ok = safeUrl(u);
  return ok && ok.indexOf('/uploads/hub/') >= 0 && ok.indexOf('..') < 0 ? ok : '';
};
const str = (v: unknown, max: number): string => (typeof v === 'string' ? v.slice(0, max) : '');

export function applyHubContent(c?: Record<string, AppContent> | null, t?: Record<string, string> | null, b?: HubBannerData | null): void {
  const nc: Record<string, AppContent> = {};
  if (c && typeof c === 'object') {
    for (const k of Object.keys(c)) {
      const x = c[k] || {};
      nc[k] = {
        title_en: str(x.title_en, 40), title_am: str(x.title_am, 40), desc_en: str(x.desc_en, 80), desc_am: str(x.desc_am, 80), badge: str(x.badge, 14),
        sort_order: typeof x.sort_order === 'number' && x.sort_order > 0 ? Math.floor(x.sort_order) : 0, icon_url: hubPicture(x.icon_url),
      };
    }
  }
  content = nc;
  const nt: Record<string, string> = {};
  if (t && typeof t === 'object') for (const k of Object.keys(t)) if (typeof t[k] === 'string') nt[k] = t[k].slice(0, 200);
  texts = nt;
  banner =
    b && typeof b === 'object' && typeof b.id === 'string'
      ? { id: b.id.slice(0, 20), title_en: str(b.title_en, 60), title_am: str(b.title_am, 60), text_en: str(b.text_en, 160), text_am: str(b.text_am, 160), image_url: hubPicture(b.image_url), link_app: str(b.link_app, 32) }
      : null;
}

export function getBanner(): HubBannerData | null {
  return banner;
}
const pick = (en: string | undefined, am: string | undefined, lang: string): string => (lang === 'am' && am ? am : en || '');

// The name to show: the owner's (Amharic when the phone is in Amharic and an Amharic name exists), else the built-in one.
export function appTitle(a: HubApp, lang: string): string {
  const c = content[a.key];
  return (c && pick(c.title_en, c.title_am, lang)) || a.title;
}
export function appSubtitle(a: HubApp, lang: string): string {
  const c = content[a.key];
  return (c && pick(c.desc_en, c.desc_am, lang)) || a.subtitle;
}
export function appIconUri(a: HubApp): string {
  return (content[a.key] && content[a.key].icon_url) || '';
}
export function appBadge(a: HubApp): string {
  return (content[a.key] && content[a.key].badge) || '';
}
// Apps with a place number (1 to 99) come first, in that order; the rest keep their built-in order after them.
export function sortApps(apps: HubApp[]): HubApp[] {
  const rank = (a: HubApp, i: number) => {
    const n = content[a.key] && content[a.key].sort_order;
    return n && n > 0 ? n : 1000 + i;
  };
  return apps.map((a, i) => ({ a, r: rank(a, i) })).sort((x, y) => x.r - y.r).map((x) => x.a);
}
export function textFor(base: 'welcome_title' | 'tagline', lang: string): string {
  return pick(texts[base + '_en'], texts[base + '_am'], lang);
}
export function supportContacts(): { phone: string; telegram: string; email: string } {
  return { phone: texts.support_phone || '', telegram: texts.support_telegram || '', email: texts.support_email || '' };
}
export function bannerText(b: HubBannerData, lang: string): { title: string; text: string } {
  return { title: pick(b.title_en, b.title_am, lang), text: pick(b.text_en, b.text_am, lang) };
}
