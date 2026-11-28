// What the app's built-in browser (WebHost) may do with a page. Pure functions, no React, so tests can load them directly.
//
// The app writes the person's Etel sign-in into the page before it loads, and the page can send the app messages
// (its sign-in, "online", "offline"). Both must only ever happen on ETEL's own site. Before this file the browser
// opened ANY https link inside the app and gave every page the sign-in and the camera.

// ETEL's own site. The app's pages, EtelID, EtelPay and the master page all live on this domain.
export const ETEL_HOSTS: string[] = ['ethelmarket.com', 'www.ethelmarket.com'];

// Payment pages that must open INSIDE the app so the person comes back to the trip or order after paying.
// They get no sign-in and their messages are ignored (they are not ETEL's own site).
export const PAYMENT_HOSTS: string[] = ['checkout.chapa.co', 'chapa.co', 'telebirr.com', 'ethiotelecom.et'];

// A test or staging build may point the app at another https site (ETEL_API_BASE_URL): that site counts as ETEL too.
export function addEtelHost(host: string): void {
  const h = String(host || '').toLowerCase();
  if (h && ETEL_HOSTS.indexOf(h) < 0) ETEL_HOSTS.push(h);
}

export function hostOf(url: string): string {
  const m = /^https:\/\/([^/?#:@]+)(:\d+)?([/?#]|$)/i.exec(String(url || ''));
  return m ? m[1].toLowerCase() : '';
}

function hostIn(host: string, list: string[], withSubdomains: boolean): boolean {
  if (!host) return false;
  return list.some((h) => host === h || (withSubdomains && host.endsWith('.' + h)));
}

// True only for https pages on ETEL's own site (not http, not "ethelmarket.com.example.com").
export function isEtelUrl(url: string): boolean {
  return hostIn(hostOf(url), ETEL_HOSTS, false);
}

export function isPaymentUrl(url: string): boolean {
  return hostIn(hostOf(url), PAYMENT_HOSTS, true);
}

const EXTERNAL_APP = /^(tel:|mailto:|sms:|geo:|intent:|https:\/\/(www\.)?(google\.com\/maps|maps\.google\.com|waze\.com|wa\.me|t\.me|play\.google\.com))/i;

// What to do with a page the browser is about to open.
//  'load'     open it inside the app
//  'external' hand it to the phone (Chrome, the phone app, Maps, Telegram ...)
//  'block'    do nothing
export function navDecision(url: string, isTopFrame: boolean = true): 'load' | 'external' | 'block' {
  const u = String(url || '');
  if (/^(about:|blob:|data:)/i.test(u)) return 'load';
  if (EXTERNAL_APP.test(u)) return 'external';
  if (isEtelUrl(u) || isPaymentUrl(u)) return 'load';
  // A frame inside an ETEL page (a map, a video): it never gets the sign-in, so it may load.
  if (!isTopFrame && /^https:\/\//i.test(u)) return 'load';
  if (/^https?:\/\//i.test(u)) return 'external';
  return 'block';
}

// The app only listens to messages from ETEL's own pages.
export function trustMessageFrom(url: string): boolean {
  return isEtelUrl(url);
}

// Wraps script the app puts into a page so it runs only on ETEL's own site. Checked again inside the page because
// the app adds the same script to every page the browser opens.
export function onlyOnEtel(js: string): string {
  const hosts = JSON.stringify(ETEL_HOSTS);
  return `(function(){try{if(location.protocol!=='https:'||${hosts}.indexOf(location.hostname.toLowerCase())<0)return;}catch(e){return;}${js}\n})();true;`;
}
