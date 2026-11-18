import Constants from 'expo-constants';

// The ETEL ecosystem. Companies marked 'live' open inside the app on the same login; 'soon' ones are shown but locked.
const apiUrl: string =
  (Constants.expoConfig?.extra?.etelApiBaseUrl as string) || 'https://ethelmarket.com/delivery/api/boot.php';
const SITE = apiUrl.replace(/\/api\/boot\.php.*$/, '');
// EtelID is the WordPress plugin on the same domain (the delivery login pages link to it here).
const ID_SITE = 'https://ethelmarket.com/EtelId';

export interface HubApp {
  key: string;
  title: string;
  subtitle: string;
  logo?: number; // the company's own logo (a bundled image); programme apps without one use `icon`
  icon?: string; // an Ionicons name drawn on a gold badge when there is no logo
  // Programme apps are shown only to accounts that belong to the programme (investor, Equb member, field agent).
  membership?: 'investor' | 'equb' | 'agent';
  url: string;
  status: 'live' | 'soon';
  // The web apps follow the shell's language and theme; EtelID is a separate site and keeps its own.
  syncPrefs: boolean;
  // What the owner chose in Admin > ETEL app (see appConfig.ts): on = visible to everyone who may open it, admins = visible to admin
  // accounts only (a test before launch), off = hidden. Without a choice: live apps are 'on', coming-soon apps are 'off'.
  mode: 'on' | 'admins' | 'off';
}

export interface HubGroup {
  title: string;
  apps: HubApp[];
}

const mk = (a: Omit<HubApp, 'status' | 'syncPrefs' | 'mode'> & Partial<Pick<HubApp, 'status' | 'syncPrefs'>>): HubApp => ({
  status: 'live',
  syncPrefs: true,
  ...a,
  mode: (a.status || 'live') === 'live' ? 'on' : 'off',
});

export const HUB_GROUPS: HubGroup[] = [
  {
    title: 'Identity',
    apps: [
      mk({ key: 'id', title: 'EtelID', subtitle: 'Your digital identity', logo: require('../../assets/apps/id.png'), url: ID_SITE + '/', syncPrefs: false }),
    ],
  },
  {
    title: 'Mobility',
    apps: [
      mk({ key: 'ride', title: 'ETEL Ride', subtitle: 'Book a ride', logo: require('../../assets/apps/ride.png'), url: SITE + '/ride/?source=app' }),
      mk({ key: 'driver', title: 'ETEL Driver', subtitle: 'Drive and earn', logo: require('../../assets/apps/driver.png'), url: SITE + '/driver/?source=app' }),
    ],
  },
  {
    title: 'Delivery and services',
    apps: [
      mk({ key: 'courier', title: 'ETEL Courier', subtitle: 'Deliver orders', logo: require('../../assets/apps/courier.png'), url: SITE + '/courier/?source=app' }),
      mk({ key: 'customer', title: 'ETEL Delivery', subtitle: 'Order anything', logo: require('../../assets/apps/customer.png'), url: SITE + '/customer.html?source=app' }),
      mk({ key: 'service', title: 'ETEL Services', subtitle: 'Service givers', logo: require('../../assets/apps/service.png'), url: SITE + '/servicegiver.html?source=app' }),
    ],
  },
  {
    title: 'My programmes',
    apps: [
      mk({ key: 'investor', title: 'ETEL Investor', subtitle: 'Your investment', icon: 'trending-up', membership: 'investor', url: SITE + '/investor/?source=app' }),
      mk({ key: 'equb', title: 'ETEL Equb', subtitle: 'Savings groups', icon: 'people', membership: 'equb', url: SITE + '/equb/?source=app' }),
      mk({ key: 'agent', title: 'ETEL Agent', subtitle: 'Earn by referring', icon: 'ribbon', membership: 'agent', url: SITE + '/agent/?source=app' }),
    ],
  },
  {
    // Staff. Hidden until the owner shows it (Admin > ETEL app); only admin accounts can open it even then.
    title: 'Staff',
    apps: [
      mk({ key: 'admin', title: 'ETEL Admin', subtitle: 'Run the platform', icon: 'shield-checkmark', url: SITE + '/admin.html?source=app', status: 'soon' }),
    ],
  },
  {
    // Not open yet: kept here so they can be switched on later (the owner gives each its address in Admin > ETEL app), but the hub does not show 'soon' apps.
    title: 'Finance and trade',
    apps: [
      mk({ key: 'pay', title: 'EtelPay', subtitle: 'Wallet and payments', logo: require('../../assets/apps/pay.png'), url: '', status: 'soon' }),
      mk({ key: 'madeya', title: 'EtelMadeya', subtitle: 'Marketplace', logo: require('../../assets/apps/madeya.png'), url: '', status: 'soon' }),
      mk({ key: 'gebeya', title: 'EtelGebeya', subtitle: 'Shops and trade', logo: require('../../assets/apps/gebeya.png'), url: '', status: 'soon' }),
    ],
  },
];

export const HUB_APPS: HubApp[] = HUB_GROUPS.reduce<HubApp[]>((all, g) => all.concat(g.apps), []);

export const SITE_ORIGIN = SITE;
// Pages the in-app browser may stay on (everything else opens outside the app).
export const ALLOWED_ORIGINS: string[] = [SITE, ID_SITE];

// ---- The owner's choices (Admin > ETEL app) ----
export interface HubOverride {
  mode: 'on' | 'admins' | 'off';
  url?: string;
}
const BASE = HUB_APPS.map((a) => ({ key: a.key, url: a.url, status: a.status, mode: a.mode }));
const BASE_ORIGINS = [...ALLOWED_ORIGINS];
const SITE_HOST = (() => {
  const m = /^https:\/\/([^/:?#]+)/i.exec(SITE);
  return m ? m[1].toLowerCase().replace(/^www\./, '') : '';
})();

// An address from the server is used only when it is https on this site's own host (the server checks too; this is the second lock).
export function safeUrl(u: string | undefined): string {
  const m = /^https:\/\/([^/:?#@\s]+)(\/[^\s"'<>]*)$/i.exec((u || '').trim());
  if (!m || m[1].toLowerCase().replace(/^www\./, '') !== SITE_HOST) return '';
  return (u || '').trim();
}
const withSource = (u: string) => u + (u.indexOf('?') < 0 ? '?source=app' : '&source=app');

// Feature switches the owner sets in the same place (Admin > ETEL app > Features): 'light' = the floating golden light over other apps,
// 'light_alert' = its sound and vibration. Default 'on'.
type Mode = 'on' | 'admins' | 'off';
let features: Record<string, Mode> = {};
export function featureOn(key: 'light' | 'light_alert' | 'force_permissions', role: string | undefined): boolean {
  const m = features[key] || 'on';
  return m === 'on' || (m === 'admins' && role === 'admin');
}

// Puts the built-in values back, then applies the owner's choices. The app objects stay the same objects, so every screen sees the change.
export function applyHubConfig(cfg: Record<string, HubOverride> | null | undefined): void {
  ALLOWED_ORIGINS.length = 0;
  BASE_ORIGINS.forEach((o) => ALLOWED_ORIGINS.push(o));
  features = {};
  for (const f of ['light', 'light_alert', 'force_permissions']) {
    const o = cfg && cfg[f];
    if (o && (o.mode === 'on' || o.mode === 'admins' || o.mode === 'off')) features[f] = o.mode;
  }
  for (const b of BASE) {
    const a = HUB_APPS.find((x) => x.key === b.key);
    if (!a) continue;
    a.url = b.url;
    a.status = b.status;
    a.mode = b.mode;
    const o = cfg && cfg[b.key];
    if (!o || (o.mode !== 'on' && o.mode !== 'admins' && o.mode !== 'off')) continue;
    const own = safeUrl(o.url);
    if (own) {
      a.url = withSource(own);
      ALLOWED_ORIGINS.push(own.split('?')[0]);
    }
    a.mode = o.mode;
    // An app is open when it is not 'off' and it has an address. 'off' keeps it in the list as 'soon' (never shown).
    a.status = o.mode !== 'off' && a.url ? 'live' : 'soon';
  }
}

// May this account see the app on the first screen? (Role and programme checks come after.)
export function hubVisible(a: HubApp, role: string | undefined): boolean {
  return a.mode === 'on' || (a.mode === 'admins' && role === 'admin');
}
