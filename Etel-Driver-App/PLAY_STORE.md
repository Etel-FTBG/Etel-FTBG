# Publishing ETEL on Google Play

The Play Store takes an **app bundle (.aab)**, not the APK. The `production` build profile already makes one, signed by
Expo (EAS) with a key it keeps for you. Version codes go up by themselves (`autoIncrement`).

## One time, by the account owner
1. **Play Console account**: https://play.google.com/console, one-time fee of 25 USD, identity check (can take days).
2. **Create the app**: name `ETEL`, default language English, App, Free. Package name is `com.etelgroup.driver`.
3. **Service account for uploads**: Play Console > Setup > API access > create a service account, give it the
   "Release manager" role, download its JSON key and save it as `Etel-Driver-App/play-service-account.json`
   (git ignores that file; never commit it).
4. **First build number** (only once): `npx eas-cli build:version:set -p android` and enter `1`.
5. **First upload must be by hand**: Google does not accept the very first bundle through the API. Run
   `npx eas-cli build -p android --profile production`, download the `.aab` from the Expo page and upload it in
   Play Console > Testing > Internal testing > Create release.
6. After that, every release is one command: `npx eas-cli build -p android --profile production --auto-submit`
   (it lands as a draft in Internal testing; promote it to Production in the console).

## Forms Google will ask for (answers drawn from the app)
- **Privacy policy URL**: https://ethelmarket.com/delivery/privacy.html
- **Account deletion URL** (required for apps with accounts): https://ethelmarket.com/delivery/delete-account.html
- **Data safety**: collects name, phone number, precise location (also in the background), photos (document uploads),
  device id for push notifications. Data is encrypted in transit. Users can ask for deletion.
- **Background location declaration** (the strict one): the Driver and Courier apps send the vehicle position while
  the person is online so riders and dispatch can follow the trip, with a visible notification while it runs. Google
  asks for a short screen-recording video: sign in as a driver, slide online, press Home, show the notification.
  Expect a review of several days; submit it early.
- **"Display over other apps" (SYSTEM_ALERT_WINDOW)**: used for the golden ETEL light that shows while a driver is
  online inside other apps. If Google objects, remove the permission and the overlay module and ship without it.
- **App access**: give reviewers a test driver login (phone and password), since most screens need an account.
- **Content rating**: questionnaire; no user-generated public content, no gambling. **Target audience**: 18+.
- **Ads**: none.

## Store listing text (edit freely)
Short description (80 characters): `One ETEL account for rides, delivery, courier and services in Hawassa.`

Full description:
> ETEL brings the Etel Group companies into one app. Sign in once with your EtelID and open ETEL Ride, ETEL Driver,
> ETEL Courier, delivery and more. Drivers, couriers and service givers slide once to go online everywhere, see
> requests, track earnings and chat with customers. Works in English and Amharic, light and dark.

Graphics needed: 512x512 icon (use `assets/icon.png`), 1024x500 feature graphic, at least 2 phone screenshots.

## How updates reach people after launch
- Small changes (screens, text, fixes) still go over the air as today, with the new progress card.
  `production` builds read the `production` channel, so publish with
  `npx eas-cli update --branch production --message "..."`.
- A change to native code, permissions, icon or name needs a new bundle, a bump of `runtimeVersion` in
  `app.base.json`, and a new Play release. Google Play then shows its own download and install progress.
