# Week 1 security hardening - what changed and what YOU must do

## Fixed in code (upload the zip)

| # | Problem | Fix |
|---|---------|-----|
| 1 | **Password reset could be brute-forced.** `reset_verify` stopped a guesser after 5 tries, but the final `reset_password` step checked the 6-digit code with no limit at all. | `reset_password` now counts wrong guesses on the code (5 max), and rate-limits per phone and per IP. |
| 2 | **`equb_cron.php` moves money with no authentication, and crashed on its first line** (`etel_env()` undefined), so Equb rounds never ran from cron. | Fixed the crash. Now runs only from the server's own cron (command line) or with `?key=` matching `EQUB_CRON_KEY` (or `CRON_DISPATCH_KEY`). |
| 3 | **`updater.php`** (uploads and extracts code) had no brute-force limit, a non-constant-time password check, and `Access-Control-Allow-Origin: *`. | 8 wrong passwords = 15 minute lockout per IP; constant-time compare; CORS limited to ethelmarket.com. |
| 4 | `debug-geocode.php` was a public temporary script that spent Google quota. `seed-zone-coords.php` / `list-zones.php` were protected only by a key written in the source (`SEED_ZONES_2026`). | Replaced all three with a "410 retired" stub. |
| 5 | **Stored XSS**: a courier's name was shown unescaped in the customer's delivery card; shop owner name, staff name, item name and description were shown unescaped to admins; address labels unescaped. | Escaped with the existing `escapeHtml()`. Script versions bumped (`customer.js` 4.51, `admin.js` 7.5, `servicegiver.js` 4.1). |
| 6 | Chapa payment start was not rate limited. | 15 starts per user per 10 minutes. |

## Do these by hand (cannot be done from code)

1. **Turn on mandatory admin 2FA.** Admin panel > Two-step sign-in: each admin enrols first,
   then set "Require 2FA for admins". Admins who have not enrolled can still open the
   2FA setup page, so this will not lock anyone out.
2. **Equb cron.** In cPanel > Cron Jobs, make sure the Equb job runs the file from the command line:
   `php /home/<account>/public_html/delivery/api/equb_cron.php`
   If it currently uses a web URL (wget/curl), add `?key=YOUR_KEY` and set `EQUB_CRON_KEY=YOUR_KEY` in `api/.env`.
3. **Updater password.** Set `UPDATER_PASSWORD` in `api/.env` to at least 20 random characters.
4. **Google Maps key.** In Google Cloud Console > Credentials, restrict the browser key to
   HTTP referrers `https://ethelmarket.com/*` and only the APIs you use. A key is also written
   in `js/courier.js` as a fallback - treat it as public and rotate it after restricting.
5. **Rotate secrets that appeared in screenshots or chats** (for example `CRON_DISPATCH_KEY`).
6. **Browser security headers: nothing to add.** `delivery/.htaccess` already sends an enforced Content-Security-Policy,
   HSTS (preload), X-Frame-Options, nosniff, Referrer-Policy and Permissions-Policy. (An earlier version of this note
   wrongly said Delivery had no CSP, and offered a snippet to paste; that snippet was removed because it would have
   replaced the stronger existing headers.) One fix was needed: `Permissions-Policy` had `camera=()`, which blocks the
   camera for every page including our own QR scanners; it is now `camera=(self)`. After uploading `.htaccess`,
   purge the LiteSpeed cache and confirm the response header shows `camera=(self)`.
7. **Optional cleanup on the server:** delete `api/debug-geocode.php`, `api/seed-zone-coords.php`,
   `api/list-zones.php`, and the unused old `delivery/servicegiver.js` (the live one is `js/servicegiver.js`).

## Still open (later weeks)

- Login token is kept in browser storage; moving it to an HttpOnly cookie removes the token-theft risk entirely (bigger change).
- Remove `'unsafe-inline'` from the existing CSP (needs the inline `onclick` handlers refactored).
- Mask phone numbers in calls (`tel:` links reveal real numbers).
- The app changes database structure while serving requests; use migrations only and give the app a lower-privilege database user.
- Courier app bundle and admin messages still insert some server text without escaping (low risk); fix in the next courier release.
- A real penetration test before real money moves.
