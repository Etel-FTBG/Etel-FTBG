# Rebuilding the ETEL Android apps in PWABuilder

Why: the Driver, Courier and Service packages you built open `https://ethelmarket.com/delivery/` instead of their own page, and four of the five packages are missing notification permission. Rebuild **Driver, Courier, Service and Ride**. Delivery already has notifications and its start page is the customer app, so rebuild it only if you want a new version number.

Do this **after** the latest deploy is live (see the checks below). PWABuilder reads the live manifest, so it must be the new one.

PWABuilder's screens change over time. If a label below doesn't match exactly, pick the closest option. The values to type are what matter.

## 0. Before you start

1. Open each in a browser and confirm they load:
   - `https://ethelmarket.com/.well-known/assetlinks.json` (shows all five `com.etel.*` packages, no redirect)
   - `https://ethelmarket.com/delivery/delete-account.html`
   - `https://ethelmarket.com/delivery/privacy.html` (has a "Deleting Your Account" section)
2. Find your **keystore** from the first build: `signing.keystore`, alias `etel-apps`, plus the passwords from `signing-key-info.txt`. Keep them private. Never commit them or paste them in chat.
3. Decide the version: the first build was version 1 (code 1). A rebuild of the same package must use a **higher version code** (for example `2`) if you already uploaded the first build to Play. If you never uploaded it, keep version 1.

## 1. Settings for each app

Go to **pwabuilder.com**, enter the **Manifest URL** instead of the site URL where it lets you, then **Package for stores → Android → Google Play → Options**.

| Setting | Driver | Courier | Service | Ride |
|---|---|---|---|---|
| Manifest URL | `https://ethelmarket.com/delivery/manifest-driver.json` | `https://ethelmarket.com/delivery/manifest-courier.json` | `https://ethelmarket.com/delivery/manifest-service.json` | `https://ethelmarket.com/delivery/manifest-ride.json` |
| Package ID | `com.etel.driver` | `com.etel.courier` | `com.etel.service` | `com.etel.ride` |
| App name | ETEL Drive — Hawassa | ETEL Courier — Hawassa | ETEL Service — Hawassa | ETEL Ride |
| Launcher name | ETEL Drive | ETEL Courier | ETEL Service | ETEL Ride |
| Host | `ethelmarket.com` | `ethelmarket.com` | `ethelmarket.com` | `ethelmarket.com` |
| **Start URL** | `/delivery/driver/?source=pwa` | `/delivery/courier/?source=pwa` | `/delivery/servicegiver.html?source=pwa` | `/delivery/ride/?source=pwa` |
| Theme color | `#007AFF` | `#D4AF37` | `#D4AF37` | `#D4AF37` |
| Background color | `#0a1428` | `#0a0703` | `#0a0703` | `#0B0B12` |
| Display | Standalone | Standalone | Standalone | Standalone |
| Orientation | Portrait | Portrait | Portrait | Portrait |

Use the same values for every app in these rows:

| Setting | Value |
|---|---|
| Icon | The app's own 512 icon, for example `https://ethelmarket.com/delivery/icons/driver-icon-512.png` |
| Maskable icon | The app's own maskable icon, for example `https://ethelmarket.com/delivery/icons/driver-icon-512-maskable.png` |
| **Notification delegation** | **ON** (needed for ride, job and order alerts) |
| **Location delegation** | **ON** for Driver, Courier and Ride (lets the app use phone location without a second browser prompt) |
| Google Play Billing | OFF (you sell physical goods and services, not digital items) |
| Fallback behavior | Custom Tabs (default) |
| Enable site settings shortcut | Optional |
| Include source code | OFF |

Replace `driver` with `courier`, `service` or `ride` in the icon URLs for the other apps.

## 2. Signing key (important)

Choose **Use mine** (use an existing key) and upload your `signing.keystore`.

- **Key alias:** `etel-apps`
- **Passwords:** the ones in your `signing-key-info.txt`

Using the same keystore keeps the **SHA-256 fingerprint** the same, so the `assetlinks.json` you already deployed stays valid. If you create a new key instead, the fingerprint changes and you must add the new one to `assetlinks.json` or the browser bar will appear in the app.

Then in Play Console use **Play App Signing** for each app. Google keeps the real signing key and your keystore becomes the upload key. After the first upload, open **Setup → App signing** and add the **Google-provided SHA-256** to `assetlinks.json` too, because users install the Google-signed build.

## 3. Download and check

1. Click **Generate** and download the zip for each app. You get an `.aab` (upload this to Play) and an `.apk` (install on a phone to test).
2. Install each `.apk` on a real Android phone.
3. Check, per app:
   - It opens **its own** screen, not the delivery landing page.
   - **No address bar** is shown. If you see one, the asset link failed: check the fingerprint and the `assetlinks.json` URL.
   - **Push notification:** trigger a test (a ride request for Driver, a job for Courier, an order for Service) with the app closed. It must arrive.
   - **Location** works (Driver, Courier, Ride).
   - **Driver only:** connect a Bluetooth receipt printer and print a receipt.
   - **Delete my account** opens the panel (Driver, Courier, Service, Ride).
   - Privacy Policy opens.
4. Remember: Driver and Courier location only updates while the app is on screen. Test this and tell drivers to keep the app open.

## 4. Upload to Google Play

For each app in **Play Console → Create app**:

1. App name, language, **App** (not game), **Free**.
2. **Policy → App content:** Privacy policy `https://ethelmarket.com/delivery/privacy.html`, **App access** (test accounts for Driver, Courier, Service), Ads (No), Content rating questionnaire, Target audience (18+), Data safety (see `docs/play-store-listings.md`), **Account deletion URL** `https://ethelmarket.com/delivery/delete-account.html`.
3. **Store listing:** paste text from `docs/play-store-listings.md` and the Amharic file; upload the 512 icon, a 1024×500 feature graphic and 2 or more phone screenshots.
4. **Testing → Internal testing → Create release:** upload the `.aab`, add yourself and a few drivers as testers, start rollout.
5. A new personal developer account must run a **closed test with 12 or more testers for 14 days** before production. An organization account usually does not.
6. When testers confirm everything works, promote to **Production**.

## 5. Other stores

The same `.apk` can go to Samsung Galaxy Store, Huawei AppGallery, Amazon Appstore and Xiaomi GetApps. Huawei phones have no Google services, so test notifications and maps there before you publish. Apple's App Store needs a different (non-TWA) build and is out of scope here.
