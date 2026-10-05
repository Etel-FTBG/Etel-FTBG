# Week 2 - speed and polish

## What the live numbers said (31 hours, 5,025 API calls, from `api_perf_minute`)

Average response across everything: **73 ms**, 4 errors, 24 slow calls. Healthy. The outliers:

| Action | Calls | Average | Worst | Why |
|---|---|---|---|---|
| `etelid_login` | 68 | **1,212 ms** | 5,224 ms | Loopback web call into EtelID's WordPress; the 4 errors were the old 5 s timeout |
| `courier_get_my_orders` | 102 | **623 ms** | 2,417 ms | 4x `ALTER TABLE` + 1 `CREATE TABLE` on **every poll** |
| `driver_update_location` | 1,338 | 99 ms | 3,539 ms | Heaviest per-call logic; fine at this scale |
| everything else | | 12-40 ms | | |

Also checked and found already good (no change needed): database indexes on `rides`, `orders`,
`ride_drivers`, `login_attempts`; skeleton loaders with shimmer on the customer home; the
main text colours (all pass WCAG AA, about 5-7 to 1).

## Changed in code

1. **`courier_get_my_orders` / `courier_get_available_orders`**: replaced the per-poll `ALTER TABLE ... ADD COLUMN`
   (which fails with "duplicate column" but still takes a table lock) with a one-line column check that only
   alters when a column is truly missing. Expect the 623 ms average to fall to the low tens of ms.
2. **EtelID sign-in** (`etelid_bridge.php`): timeout 5 s -> 10 s for sign-in only, 4 s connect timeout, one quick
   retry if the connection itself could not be made. A slow-but-working sign-in now finishes instead of failing.
3. **`js/visibility-throttle.js`** (new, customer + rider pages only): pauses steady 3-60 s polls while the app is
   in the background and runs one immediately when the user comes back. About a quarter of API calls were
   background polling by riders' phones. Does NOT touch the driver app, timers under 3 s, or the 2-minute ride
   safety check-in. Kill switch: `window.ETEL_NO_VISIBILITY_THROTTLE = true`.
4. **Customer app**: item images load lazily (`customer.js` 4.52).
5. **Faster first paint**: outside QR libraries (and the local jsQR on the shop page) are `defer`red on the courier
   and service-giver pages; they are only used on demand.
6. **Admin**: Chart.js pinned to 4.4.1 (was "latest, unpinned" from a CDN).
7. **Login screen**: tab labels 8px -> 11px with 58px tap targets and a one-row layout down to 320px phones
   (it wrapped to two rows before); form labels and footer 9px -> 11px; "Create EtelID Account" is now a clear gold
   button (it looked disabled); footer text no longer sits under the floating buttons.

## Do by hand

- Append `performance.htaccess.txt` to `public_html/delivery/.htaccess` (compression + image caching), then check
  `content-encoding: gzip` on `customer.js` in DevTools > Network. This is the biggest first-visit saving
  (about 210 KB -> 45-60 KB) if the server is not already compressing.
- After uploading, tell me and I will re-read the live timings to confirm `courier_get_my_orders` dropped.
- Optional: delete `delivery/icons/master-logo-icons.zip` (9 MB, publicly downloadable, not used by the apps).

## Not done / next

- `driver_update_location` (99 ms, most-called): worth profiling once there are many drivers online.
- `ride_path_points` is the largest table (about 8,900 rows after a few days): add a retention job before launch.
- EtelID legacy password hashes (plain SHA-256 with a salt in the source) still accepted for old accounts:
  force a reset/upgrade.
- Minify `customer.js` / `admin.js` (about 210 KB / 178 KB readable source) once compression is confirmed.
