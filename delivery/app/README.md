# ETEL app updates (native builds)

Over-the-air updates cover JavaScript changes. A change to native code (a new APK build) is delivered from this folder:
the ETEL app reads `version.json`, and when `versionCode` is higher than the installed build it offers
"Download and install", shows live progress, checks the file's SHA-256, and opens Android's installer.

## Publishing a new build
1. Run the EAS build (preview profile, APK). On the build page note **Version code** (the number, e.g. 12) and **App version**.
2. Download the APK and upload it here as `etel.apk` (replace the old one).
3. Get its SHA-256 (optional but recommended): `sha256sum etel.apk`, or any online file-hash tool.
4. Edit `version.json`:
   - `versionCode`: the build's Version code (must be higher than what phones have installed)
   - `versionName`: what people see, e.g. `1.8.0`
   - `url`: `etel.apk` (or a full https address)
   - `sha256`: the hash from step 3 (leave empty to skip the check)
   - `sizeBytes`: file size in bytes (shows size before the download starts)
   - `notes`: short "What's new" lines
   - `required`: `true` blocks the app behind the update sheet until installed
5. Upload `version.json` last, so phones never see it before the APK is in place.

Set `versionCode` to `0` to switch the update offer off.
