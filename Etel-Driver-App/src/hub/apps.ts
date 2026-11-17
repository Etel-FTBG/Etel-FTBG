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
}

export interface HubGroup {
  title: string;
  apps: HubApp[];
}

const mk = (a: Omit<HubApp, 'status' | 'syncPrefs'> & Partial<Pick<HubApp, 'status' | 'syncPrefs'>>): HubApp => ({
  status: 'live',
  syncPrefs: true,
  ...a,
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
    // Not open yet: kept here so they can be switched on later, but the hub does not show 'soon' apps.
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
