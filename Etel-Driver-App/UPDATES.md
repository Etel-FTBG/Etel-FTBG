# How the ETEL app gets updates

Most changes reach phones **without a new APK**:

1. A change is merged to `main`.
2. The workflow `publish-update.yml` publishes it to the `preview` channel (you can also run it by hand:
   Expo website > Workflows > Run workflow > `publish-update.yml`).
3. Phones download it in the background on the next app start. A gold bar "A new version is ready" appears; one tap on
   **Update** restarts into it.

## When a new APK is still needed
Over-the-air updates can only change JavaScript, screens, text and images. A **new build** is needed when a change touches
native code or the app's shell: a new native library, new permissions, the app icon, name or splash, or the Kotlin
overlay module. When that happens, bump `runtimeVersion` in `app.base.json` (for example "1" to "2") in the same change.
Phones on the old APK keep running their last compatible update until they install the new APK.

## Channels
- `preview` builds (the APK you install for testing) read the `preview` channel.
- `production` builds (Play Store) read the `production` channel.
