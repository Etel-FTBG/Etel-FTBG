// When the golden ETEL light is shown on top of other apps. One rule for every account (driver, courier, service giver, customer, admin):
// the light shows ONLY while the account is online (slid to online), the app is out of sight, the owner has not switched the light off and the phone
// allows drawing over other apps. Being offline hides it even when the account has a live trip or order; being online shows it even with zero counts.
export interface LightInput {
  away: boolean;       // the app is not in front
  online: boolean;     // slid to online
  featureOn: boolean;  // the owner's switch (Admin > ETEL app > Features)
  canDraw: boolean;    // "Display over other apps" is allowed on this phone
}
// A push that wakes the app in the background (a new ride request or order) may light the golden light by itself only for the accounts that work online
// (a request push goes only to those). Customers and admins get other pushes (driver arrived, a reply...) that must never bring the light up: for them
// the light follows the "Slide to go online" switch in the open app, nothing else.
export function wakeMayShowLight(role: string | undefined): boolean {
  return role === 'driver' || role === 'courier' || role === 'service_giver';
}
export function shouldShowLight(i: LightInput): boolean {
  return i.away && i.online && i.featureOn && i.canDraw;
}
