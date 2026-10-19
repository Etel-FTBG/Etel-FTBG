import Constants from 'expo-constants';

// The ETEL web apps, opened inside the native shell. They live on the same site as the API, so they share one login.
const apiUrl: string =
  (Constants.expoConfig?.extra?.etelApiBaseUrl as string) || 'https://ethelmarket.com/delivery/api/boot.php';
const SITE = apiUrl.replace(/\/api\/boot\.php.*$/, '');

export interface HubApp {
  key: string;
  title: string;
  subtitle: string;
  icon: string; // an Ionicons name
  url: string;
}

export const HUB_APPS: HubApp[] = [
  { key: 'ride', title: 'ETEL Ride', subtitle: 'Book a ride', icon: 'car-sport', url: SITE + '/ride/?source=app' },
  { key: 'driver', title: 'ETEL Driver', subtitle: 'Drive and earn', icon: 'speedometer', url: SITE + '/driver/?source=app' },
  { key: 'courier', title: 'ETEL Courier', subtitle: 'Deliver orders', icon: 'bicycle', url: SITE + '/courier/?source=app' },
  { key: 'customer', title: 'ETEL Delivery', subtitle: 'Order anything', icon: 'bag-handle', url: SITE + '/customer.html?source=app' },
  { key: 'service', title: 'ETEL Services', subtitle: 'Service givers', icon: 'construct', url: SITE + '/servicegiver.html?source=app' },
];

export const SITE_ORIGIN = SITE;
