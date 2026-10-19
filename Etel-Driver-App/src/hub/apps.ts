import Constants from 'expo-constants';

// The ETEL web apps, opened inside the native shell. They live on the same site as the API, so they share one login.
const apiUrl: string =
  (Constants.expoConfig?.extra?.etelApiBaseUrl as string) || 'https://ethelmarket.com/delivery/api/boot.php';
const SITE = apiUrl.replace(/\/api\/boot\.php.*$/, '');

export interface HubApp {
  key: string;
  title: string;
  subtitle: string;
  logo: number; // the company's own logo (a bundled image)
  url: string;
}

export const HUB_APPS: HubApp[] = [
  { key: 'ride', title: 'ETEL Ride', subtitle: 'Book a ride', logo: require('../../assets/apps/ride.png'), url: SITE + '/ride/?source=app' },
  { key: 'driver', title: 'ETEL Driver', subtitle: 'Drive and earn', logo: require('../../assets/apps/driver.png'), url: SITE + '/driver/?source=app' },
  { key: 'courier', title: 'ETEL Courier', subtitle: 'Deliver orders', logo: require('../../assets/apps/courier.png'), url: SITE + '/courier/?source=app' },
  { key: 'customer', title: 'ETEL Delivery', subtitle: 'Order anything', logo: require('../../assets/apps/customer.png'), url: SITE + '/customer.html?source=app' },
  { key: 'service', title: 'ETEL Services', subtitle: 'Service givers', logo: require('../../assets/apps/service.png'), url: SITE + '/servicegiver.html?source=app' },
  { key: 'admin', title: 'ETEL Admin', subtitle: 'Management', logo: require('../../assets/apps/admin.png'), url: SITE + '/admin.html?source=app' },
];

export const SITE_ORIGIN = SITE;
