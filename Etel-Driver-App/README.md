# ETEL Drive (native Android driver app)

Talks directly to the existing `delivery/api/boot.php` backend — same database
the Driver PWA and Admin dashboard already use. See
`C:\Users\dagianan\.claude\plans\flickering-pondering-lerdorf.md` for the full plan.

## Setup

```bash
npm install
cp .env.example .env   # then fill in GOOGLE_MAPS_API_KEY
```

## Run during development

```bash
npx expo start
```

Scan the QR code with **Expo Go** on an Android phone for the fastest iteration loop.
Note: Expo Go can preview most of the app, but background location with a foreground
service (the whole reason this isn't a PWA) only works in a real build — use
`eas build --profile development` and install that on-device once background location
needs testing.

## Build a signed APK

```bash
npx eas login                              # one-time, needs a (free) Expo account
npx eas build -p android --profile preview # produces an installable .apk
```

The `preview` profile builds an APK (not an `.aab`) so it can be sideloaded directly,
same as the existing `delivery/apk/*.apk` files. `production` builds an `.aab` for Play
Store submission if that's ever wanted.

## What's built (Milestones 1-4 of the plan)

- Bearer-token auth against `login` (driver-tab only), token in `expo-secure-store`,
  automatic forced-logout on 401.
- Home screen: online/offline toggle (`driver_go_online`/`driver_go_offline`), nearby
  ride requests polled every 10s, accept flow.
- Active ride screen: map (pickup/dropoff pins), start → arrived flow
  (`driver_start_ride`, `driver_toggle_wait`, `driver_arrived`), cancel, status polled
  every 6s — matches the web app's SSE-fallback cadence.
- Background location: `expo-location` + `TaskManager`, ~5s pings to
  `driver_update_location` with a persistent Android foreground-service notification,
  active even when the app is backgrounded — this is the actual capability a TWA can't
  give you.

## What's not built yet (Milestones 5-7 of the plan)

- In-ride chat (`get_ride_messages`/`send_ride_message`, 3s poll).
- Driver profile/earnings/compliance/announcements screens.
- Real push notifications — needs a small **backend** addition too (a `save_push_token`
  action + an Expo-push-forwarding helper in `push.php`), not just client work. Until
  then, the app only knows about new ride requests via the 10s poll while it's open.

## Project layout

- `src/api/client.ts` — the single `boot(action, body)` fetch wrapper every call goes
  through.
- `src/api/auth.ts`, `src/api/ride.ts` — typed wrappers per backend action.
- `src/context/AuthContext.tsx` — session state, wired to the 401 handler.
- `src/location/backgroundLocationTask.ts` — the TaskManager background task.
- `src/screens/` — Login, Home, ActiveRide.
