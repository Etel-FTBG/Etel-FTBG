# Play Store readiness review — ETEL apps (Delivery, Ride, Drive, Courier, Service)

Scope: the `delivery/` PWA code in this repo, the five PWABuilder packages you uploaded, and the Play policies that apply to them. I read code and packages. I did not run the apps on a phone or look at the live server, so items marked "verify" need a real check.

## Verdict

**Not ready to submit yet.** The system is technically sound (HTTPS, CSP and security headers, no secrets in git, CI green, working service worker and offline page). There are 4 blockers that will get a submission rejected or the apps pulled, and several fixes to make first.

## Blockers (fix before any submission)

1. **Driver, Courier and Service packages launch the wrong page.** All three open `https://ethelmarket.com/delivery/` instead of their own page. Rebuild in PWABuilder with the start URLs from the earlier table. Google rejects near-duplicate apps.
2. **No proper account deletion.** Play requires (a) a delete-account path inside the app and (b) a public web page or URL for deletion requests, which you enter in the Data Safety form.
   - Today only Ride has "Delete My Account", and it just sends a support message (`ride/index.html` `requestRidePrivacyAction`).
   - Customer (Delivery), Driver, Courier and Service have no delete or privacy entry at all.
   - Fix: add the same Settings → Privacy → Delete account to every app, and publish a deletion-request page (for example `https://ethelmarket.com/delete-account.html`) that explains what is deleted and what is kept for legal reasons, and how long it takes.
3. **Privacy policy doesn't cover what the apps actually collect.** `privacy.html` mentions name, phone, password, orders and server logs. It does not mention:
   - precise location (rider, driver and courier)
   - push notifications
   - camera and microphone use, and Bluetooth printers
   - uploaded ID or document photos
   - payment processors (Chapa, Telebirr) and third parties that receive data (Google Maps, OpenStreetMap Nominatim, Web Push)
   - how to delete your account

   Play compares the policy against your Data Safety answers and the app's behavior. Update it and link it from the store listing.
4. **Privacy and Terms links inside the apps are dead.** `customer.html` and `servicegiver.html` use `href="#"` for Privacy and Terms. Play wants a working policy link inside the app. Point them at `https://ethelmarket.com/privacy.html` and `terms.html`.

## High priority (fix before launch, or the apps won't work properly)

5. **Notifications are not enabled in four of the five packages.** Only Delivery has `POST_NOTIFICATIONS`. Driver, Courier, Ride and Service need it, because job and ride alerts are the core of those apps. In PWABuilder, turn on notification delegation. Test that a push arrives with the app closed.
6. **Driver and Courier location tracking stops in the background.** The code uses `watchPosition` and a screen wake lock (`js/driver.js`), which only work while the app is on screen. A driver who switches apps or locks the phone stops sending location. That is a product limit of a TWA, not a policy problem. Either tell drivers to keep the app open (the wake lock helps), or plan a native wrapper later with a proper foreground service. Do not claim background tracking in the listing or the Data Safety form unless it exists.
7. **Broken shortcut icons.** The manifests point at `assets/images/etel-*-coin.png`, which doesn't exist. The files are in `icons/`, and some (courier, service, admin coins) aren't there at all. Fix the paths or drop the shortcut icons. Also add 2 or more screenshots to the manifests if you want richer install prompts.
8. **Target API level.** I couldn't read it from the packages here. Play rejects uploads below its current required `targetSdkVersion`. PWABuilder's latest version normally handles this, but check the upload result in Play Console and rebuild with the newest PWABuilder if it complains.
9. **Reviewer access.** Driver, Courier and Service need an approved account. Create test accounts for each and put the credentials in App content → App access, or Google will reject the app as unreviewable.

## Medium priority

10. **One signing key for all five apps.** Use Play App Signing so Google holds the real key, and keep the keystore backed up offline. If the keystore leaks or is lost, all five are affected.
11. **Service page scope.** The service app lives at `/delivery/servicegiver.html`, so its scope is all of `/delivery/`. Moving it to its own folder would tighten that.
12. **Admin panel.** The Admin PWA has no TWA package. Keep it web-only. Don't publish it.
13. **Old API helper files.** `debug-geocode.php`, `list-zones.php` and `seed-zone-coords.php` are stubbed out and say they are safe to delete. Delete them from the server. `diagnose.php` is key-protected and fine.
14. **Flaky CI test.** EtelERP's concurrency test (invoice numbers gapless) failed once and passed on re-run. It is unrelated to the apps, but it may be a real race worth fixing separately.

## What already looks good

- HTTPS everywhere, HSTS, CSP, `nosniff`, clickjacking protection.
- Secrets come from `api/.env`, not from git. No keystore or key files in the repo.
- Service worker with offline fallback, and valid manifests (icons incl. maskable, standalone display).
- CI runs migrations, security and business-logic smoke tests, and passes.
- Digital Asset Links file now merged for all five packages (verify it loads live).
- Payments are for physical goods and services, so Google Play Billing is not required.

## Pre-submission checklist

- [ ] Merge and deploy; open `https://ethelmarket.com/.well-known/assetlinks.json`
- [ ] Fix blockers 1–4
- [ ] Rebuild all five in PWABuilder: correct start URLs, notification delegation, same keystore
- [ ] Install each APK on a phone: opens its own page, no address bar, push works, location works, Bluetooth printer works (Driver)
- [ ] Privacy policy URL and deletion-request URL in each Play listing
- [ ] Data Safety form: location, personal info, financial info, photos
- [ ] Test accounts for Drive, Courier and Service
- [ ] Screenshots, 512px icon and feature graphic for all five
- [ ] Upload `.aab` to Internal testing first, then closed testing (new personal accounts need 12 testers for 14 days)
