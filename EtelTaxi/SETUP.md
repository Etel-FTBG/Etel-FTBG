# Etel Taxi - Setup

Digitizes the association permit + monthly dues system: a driver registers
with their taxi association, pays a monthly due (default ETB 100), and
shows a QR code from their phone instead of carrying the paper receipt.
A traffic officer scans the QR to see the driver's real-time permit
status - active, in the grace period, or overdue with a penalty owed.

## What's real vs. a placeholder right now

- **Real:** driver/admin accounts, association + route management, the
  exact due/grace/penalty date logic described (payable from the end of
  one month through day 5 of the next, penalty after that), Telebirr/
  CBE/Abysinia payment via reference-number submission + admin
  verification (same proven pattern as Delivery's own bank-transfer
  checkout), the QR-based permit lookup for traffic officers, a
  plate-number lookup for when the driver/phone isn't present (officers
  sign in with their own EtelID on `verify.html`'s "Plate Lookup" tab -
  see "Traffic officers" below), and push-notification reminders
  (due-soon / grace-period / penalty-applied) - the logic that decides
  who gets reminded and when is done.
- **Placeholder, not faked:** paying via an Etel Pay wallet. The option
  is in the UI and the backend, but it returns an honest "not available
  yet" error rather than pretending to move money. Building this for
  real needs Etel Pay's own codebase uploaded to build against its
  actual schema, the same way every other real integration in Etel Group
  was built - not guessed.
- **Push reminders work already, no separate setup needed:** uses
  standard Web Push (RFC 8291/8292), reusing the same VAPID identity
  Delivery & Ride already has - not Firebase, no separate Google project
  to create. See "Push notifications" below only if you want a
  different VAPID identity than Delivery's, or need the cron job set up.

## Push notifications

Standard Web Push, same mechanism (and same VAPID key pair, by default)
as Delivery & Ride - `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY_PEM`/
`VAPID_SUBJECT` are already set in `api/.env`. Only remaining step:

1. Set up a daily cron job (cPanel -> Cron Jobs) to run:
   `php /home/YOURUSER/public_html/EtelTaxi/api/cron/send_reminders.php`
   once a day. If your host doesn't support real cron jobs, you can
   instead hit `api/cron/send_reminders.php?key=YOUR_TAXI_ADMIN_KEY`
   from any external "URL cron" service once a day.
2. Drivers tap "Enable Payment Reminders" in `driver.html` once they've
   logged in - this asks for notification permission and subscribes
   their device via the browser's own Push API (`sw.js`).

If you ever want EtelTaxi to use its own separate VAPID identity instead
of sharing Delivery's, generate a new key pair (e.g. via
`openssl ecparam -genkey -name prime256v1 -noout`, converted to the raw
public point) and replace the three `VAPID_*` values in `api/.env` -
nothing else needs to change.

`js/firebase-config.example.js` is no longer used and can be deleted.

## Traffic officers

A traffic officer signs in on `verify.html`'s "Plate Lookup" tab with
their own real EtelID - no shared key to hand out or lose track of.
Same security boundary as admin EtelID sign-in: it only works if that
EtelID's phone number already matches an **existing** officer account -
it never lets any EtelID holder become an officer on their own.

1. In `admin.html`, go to Traffic Officers (super admin only) and add
   the officer's full name and phone number - the phone must match the
   phone on their real EtelID account.
2. The officer opens `verify.html`, taps "Plate Lookup", and signs in
   with their EtelID once - their session then persists on that device
   for 30 days, same as driver/admin sessions.
3. From there they just type a plate number and get the same
   PASS/GRACE/OVERDUE result the QR-scan path shows.

## Deploy steps

1. Create a MySQL database and a DB user with access to it (and SELECT
   access to EtelID's own database, if you want cross-app sign-in to
   work here too).
2. Copy `api/.env.example` to `api/.env` and fill in real values -
   especially `DB_PASS`, `TAXI_ADMIN_KEY`, and `TAXI_QR_SIGNING_KEY`
   (generate long random strings for the last two, don't reuse a
   guessable value).
3. Upload the whole project to your server (same ZIP-upload-via-cPanel
   approach as the rest of Etel Group, or however you deploy Delivery).
   For ongoing deploys after this first one, this project has its own
   self-service updater at `update-panel.html` (backed by
   `updater.php`) - same tool you already use for EtelAI, adapted for
   this project. Set `ETEL_TAXI_UPDATER_PASS` as a cPanel environment
   variable before using it (same pattern as EtelAI's updater).
4. Run the migration: visit
   `https://yourdomain/EtelTaxi/api/migrate.php?key=YOUR_TAXI_ADMIN_KEY&apply=1`
   (drop `&apply=1` first to see a dry run of what it would do). Safe to
   re-run any time - already-applied files are skipped automatically.
5. Create your first admin account:
   `https://yourdomain/EtelTaxi/api/seed-admin.php?key=YOUR_TAXI_ADMIN_KEY&phone=YOUR_PHONE&pin=YOUR_PIN&name=Your+Name`
6. Sign in at `admin.html` with that phone/PIN. From there: add real
   associations (edit the one seeded example to match your real
   association, or add more), fill in real bank account numbers under
   Bank Accounts, and start registering drivers.
7. Drivers use `driver.html` to register themselves (if you've told them
   their association) or you can register them yourself from the admin
   Drivers panel, which gives you a starter PIN to hand them.

## Adding this to the Etel Group dashboard

Once deployed, this is a normal Etel Group subsidiary the same way
Delivery/EtelPay/EtelGebeya are - add a link/tile to it from wherever the
Etel Group's own landing page lists its companies, same as the others.
