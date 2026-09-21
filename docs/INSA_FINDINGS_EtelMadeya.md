# INSA Pre-Submission Security Findings — Etel Madeya

First-time INSA pre-submission audit of Etel Madeya (Etel Group's fuel-station queue
management system), living at `EtelMadeya/` in this repo, deployed at
`ethelmarket.com/Etel-Madeya/`. No prior audit document existed for this system — this
file is written from scratch. Scope covers all 12 PHP files under `EtelMadeya/`
(`api/index.php`, `api/service_nfc_register.php`, `api/service_nfc_check_role.php`,
`controller.php`, `admin/api.php`, `admin/index.php`, `index.php`, and the five
`includes/*.php` shared helpers), every HTML/JS file under `assets/` that renders
API data, the PWA files under `pwa/`, `.htaccess`, and the whole `EtelMadeya/` tree for
any unprotected sensitive path. No deployment was performed — everything below is a
code-level fix in the git working tree. Deployment to `ethelmarket.com/Etel-Madeya/` is
a separate manual step handled by Dagi.

Every finding below was produced by reading the actual code (file/function/action names
cited throughout), not inferred. Every `.php` file edited was verified with `php -l` (no
syntax errors) and checked for **new** non-ASCII bytes introduced by the edit
(`python3 -c "print([ch for ch in open(f).read() if ord(ch)>127])"`, diffed against what
was already in the file). Etel Madeya is not on the explicit hard-ASCII-constraint list
(only EtelID and EtelPay are), but ASCII discipline was applied anyway to everything newly
written in this pass — confirmed no new non-ASCII bytes were introduced by any edit; this
codebase's existing box-drawing header comments (`═`, `─`) and one pre-existing em-dash in
`api/index.php` (line 488, untouched by this pass) are left as-is.

Etel Madeya is architecturally the simplest of the four Etel Group systems audited so far
(after Delivery & Ride, EtelGebeya, and EtelPay): two flat action-dispatch routers
(`api/index.php` for drivers/controllers, `admin/api.php` for the admin panel — both a
single `switch ($action)` block, no `boot.php`-style whitelist, no MVC layer), a shared
`includes/` layer (`config.php`, `database-new.php`'s `Database`/`db()` PDO wrapper,
`security.php`'s auth/session/validation helpers, `queue_logic.php`'s shared registration
logic, `etelid_bridge.php`'s outbound EtelID Service API client), two standalone
service-to-service NFC endpoints added this session
(`service_nfc_register.php`/`service_nfc_check_role.php`), and three plain HTML shells
(`index.php`, `controller.php`, `admin/index.php`) whose actual logic lives in
`assets/js/{driver,controller,admin}-app.js`.

---

## 1. Authorization enforcement

**Checked:** every `case` in `api/index.php`'s 20 driver/controller actions and
`admin/api.php`'s 19 admin actions, for whether the action requires a valid session
(`requireControllerAuth()`/`requireAdminAuth()`, both in `includes/security.php`), and
whether any action that accepts an ID trusts it without verifying the authenticated
caller actually owns the row it refers to (IDOR class) — the specific concern the task
brief raised about controllers only being able to see/act on their own station's queue.
Also checked both service-to-service NFC endpoints and the EtelID SSO login flows for the
same.

### Fixed — Item 1: `serve_vehicle`, `move_to_vip`, `move_to_waiting` let any
authenticated controller act on any OTHER station's queue rows (High — cross-station IDOR)

**Finding:** every read-only queue action in `api/index.php` (`get_queue`, `get_stats`,
`clear_queue`, `bulk_import`, `get_report`) correctly scopes its query to
`WHERE controller_id = ?` using `$_SESSION['controller_id']` — confirmed by reading each
one. But the three state-changing single-row actions did not:
- `serve_vehicle` read the target row with a bare `SELECT * FROM queue WHERE id = ?` (no
  `controller_id` filter at all), then moved it into `report` (crediting
  `$vehicle['controller_id']`'s fuel-used stats — the *real* owning station, not the
  caller's) and deleted it from `queue`.
- `move_to_vip` and `move_to_waiting` both ran `UPDATE queue SET queue_type = ? WHERE
  id = ?` with no `controller_id` filter either.

Since `queue_id` is a small sequential auto-increment integer supplied directly by the
client, any controller logged in at Station A could serve, or move to VIP/waiting, any
vehicle currently queued at Station B just by guessing/enumerating a `queue_id` — pulling
another station's vehicle out of its own queue (denying that driver their position),
recording it as served at the wrong station, and crediting fuel-used stats to whichever
station actually clicked Serve rather than the one that ran out of fuel for that vehicle.
This is exactly the kind of missing-ownership-check bug the task brief asked to look for.

**Fix applied** in `EtelMadeya/api/index.php`:
```php
// serve_vehicle
$vehicle = db()->fetchOne(
    "SELECT * FROM queue WHERE id = ? AND controller_id = ?",
    [$queueId, $_SESSION['controller_id']]
);

// move_to_vip / move_to_waiting
db()->update('queue', ['queue_type' => 'vip'], 'id = ? AND controller_id = ?', [$queueId, $_SESSION['controller_id']]);
```
All three now scope to the caller's own `controller_id`, matching the pattern every other
controller action in this file already used. A request for another station's `queue_id`
now returns "Vehicle not found" (a no-op) instead of acting on it. Verified with `php -l`
(no syntax errors) and the ASCII-byte scan (no new non-ASCII bytes).

### Fixed — Item 2: `check_position` (public, unauthenticated) returned another
person's full name and phone number to anyone who knew or guessed a plate number
(Medium — PII exposure)

**Finding:** `check_position` is a public action (no auth — any driver types a plate
into the homepage to check their queue position). Its query was `SELECT q.*, ...`,
which includes `queue.driver_name` and `queue.driver_phone` — collected optionally by a
controller at registration time. Neither field is ever rendered by
`driver-app.js`'s `checkPosition()` handler (confirmed by reading it — only
`position`/`ahead`/`plate_number`/`vehicle_type_name`/`queue_type`/`token`/`station_name`
are displayed), so this was purely an unused-but-shipped data leak in the JSON response:
anyone who knew (plates are semi-public, visible on the vehicle itself) or brute-forced a
plate number could read back the driver's name and phone number tied to it via this
public endpoint. `driver_active_queue`, the other public queue-lookup action in the same
file, already avoided this by selecting an explicit column list that excludes both fields
— `check_position` was the one inconsistent case.

**Fix applied** in `EtelMadeya/api/index.php`'s `check_position`: replaced `SELECT q.*, ...`
with an explicit column list (`id, controller_id, vehicle_type_id, plate_number,
plate_parity, token, position, queue_type, status, batch_id, registered_at`, plus the
joined vehicle-type/station columns already selected) — `driver_name`/`driver_phone` are
no longer selected or returned. Verified with `php -l` and the ASCII-byte scan.

### Verified clean

- **No client-supplied "role"/"is_admin"/"controller_id" field is ever written to a
  privileged column.** `admin_users.role` and `controllers.staff_role`/
  `is_etelgroup_managed` are only ever written by `admin_etelid_login`/
  `controller_etelid_login` (both server-resolve the value from `etelid_bridge_check_role()`,
  never from client-supplied POST data) or by `admin/api.php`'s
  `create_controller`/`update_controller`, which are themselves gated behind
  `requireAdminAuth()`.
- **Local username/password login is fully retired on both surfaces.**
  `api/index.php`'s `controller_login` and `admin/api.php`'s `login` both
  unconditionally return `403` with "Local login has been disabled" — grepped for any
  other code path that reads `controllers.password`/`admin_users.password` for
  authentication and found none; both columns are only ever written (with a random,
  unusable placeholder hash via `password_hash(bin2hex(random_bytes(24 or 32)), ...)`),
  never compared against at login. EtelID SSO (`controller_etelid_login`/
  `admin_etelid_login`) is the only working sign-in path for either surface.
- **`admin_etelid_login` correctly restricts the Admin Panel to the `admin` EtelGroup
  role specifically**, not any role at `etel-madeya` — its own code comment explains why
  (`admin/api.php` lines 67–77): `etelid_bridge_check_role('etel-madeya', ...)` confirms
  *some* role exists, but a controller's role would also pass that check, so the code
  additionally requires `strcasecmp(trim($egRole['role_at']), 'admin') === 0` before
  treating the caller as eligible for admin auto-provisioning/continued access — otherwise
  any controller signing in at the admin panel by mistake (or on purpose) would get full
  admin access. Confirmed this guard is genuinely applied before both the "create new
  admin" and "keep managed admin's access" branches, not just checked and ignored.
- **`controller_etelid_login` re-checks the current EtelGroup role on every login, not
  just at account creation** (`api/index.php` lines 247–282) — a managed controller whose
  role was later removed from the central Employees panel is blocked with "Your Etel
  Group role at Etel Madeya has been removed" on their very next login, even with a still
  otherwise-valid EtelID password. A true legacy controller (never EtelGroup-managed) is
  correctly left untouched if no `egRole` exists. Same pattern independently verified in
  `admin/api.php`'s `admin_etelid_login`.
- **Both standalone NFC service endpoints** (`api/service_nfc_register.php`,
  `api/service_nfc_check_role.php`) were re-read independently in this pass (added this
  session, not carried over from an earlier audit): both correctly reject the
  `ETELID_SERVICE_KEY` placeholder case-insensitively
  (`strcasecmp(trim((string) ETELID_SERVICE_KEY), 'CHANGE_ME') === 0`, lines 59 and 60
  respectively) before accepting any call, both use `hash_equals()` for the actual key
  comparison (falling back to `===` only if `hash_equals` doesn't exist, matching
  EtelPay's own service scripts), both accept the key via header or `?key=` fallback
  (this host's known FastCGI custom-header-stripping issue, per the shared ops notes),
  and both return a generic `'Invalid or missing service key.'` on mismatch with no debug
  fingerprint of any kind (the exact class of leak found and fixed in EtelPay's
  `etelid_members_list.php` in the sibling audit — not present here).
  `service_nfc_register.php` additionally never lets a bare NFC tap register a vehicle on
  its own: it requires either an existing `controllers` row matching the caller's phone,
  or (for an admin/manager acting on behalf of a station) an explicit `station_id` the
  caller must supply after being shown the list of active stations — it never infers or
  defaults a station.
- **`madeyaFindAdminByPhone()`** (`includes/queue_logic.php`) deliberately restricts
  which admin roles can act as a stand-in controller via NFC to `role IN ('admin',
  'manager')`, excluding `'staff'` — confirmed by reading the function and its own code
  comment explaining this is intentional.

### Needs Dagi's judgment call

- **`checkRateLimit()` (`includes/security.php`) stores its counters in `$_SESSION`, not
  a shared, durable store keyed purely by IP.** Both EtelID-SSO login actions
  (`controller_etelid_login`, `admin_etelid_login`) call it as
  `checkRateLimit('madeya_ctrl_etelid_' . getClientIP(), 5, 900)` — the *key* is
  IP-derived, but the *storage* is the caller's own PHP session, which is entirely
  attacker-controlled (a fresh request with no session cookie gets a fresh, empty
  counter). This means the 5-attempts-per-15-minutes limit is trivially bypassed by
  simply not sending a session cookie (or clearing it) between attempts — it only throttles
  a single browser tab that keeps its cookies, not a scripted attacker. The actual
  password check happens server-to-server against EtelID's own `authenticate` endpoint
  (`etelid_bridge_authenticate()`), so the real blast radius depends on whether EtelID's
  own Service API independently rate-limits repeated `authenticate` calls for the same
  identifier — not confirmed from this codebase alone, since that endpoint lives in
  EtelID's own WordPress plugin, outside this audit's scope. Not fixed here: a real fix
  needs a durable, session-independent store (a small DB table keyed by IP/phone with a
  timestamp window, the same shape EtelPay's `ep_login_attempts` table uses) rather than
  a same-file one-line patch, and that's a schema decision plus a new table this pass
  can't deploy-test. Flagging clearly rather than silently leaving it undocumented.
- **`admin_users.role` has no enforced capability tiers beyond "logged in as any admin."**
  Every `requireAdminAuth()`-gated action in `admin/api.php` grants the same full set of
  admin capabilities regardless of what `role` says — there is no per-action capability
  check the way Delivery & Ride/EtelTaxi/EtelGebeya's `role_permissions` system now
  provides (per the shared Etel Group ops notes). For a system this size (one
  `admin_users` table, no distinct "finance" vs. "ops" admin distinction anywhere in the
  UI either) this may be entirely intentional — flagging only because the column exists
  and could imply a tiering that isn't actually enforced anywhere in code.

---

## 2. SQL injection review

**Checked:** every `db()->query()`/`fetchOne()`/`fetchAll()`/`insert()`/`update()`/
`delete()`/`count()` call site across all 12 PHP files, plus `Database`'s own
`insert`/`update`/`delete`/`count` helper methods in `includes/database-new.php` — traced
every place a variable reaches a SQL string, whether via a bound placeholder or direct
concatenation.

### Findings

**None. No SQL injection was found in any request-input-reachable query.**

### Verified clean

- **Every value from `$_GET`/`$_POST`/a decoded JSON body reaches SQL only via a bound
  `?` placeholder.** `includes/database-new.php`'s single `PDO` construction site sets
  `PDO::ATTR_EMULATE_PREPARES => false` (line 16) — real server-side prepared statements,
  not string-substituted ones, across the whole codebase (there's only one `Database`
  class, used everywhere; no second, independent DB-connection code path exists anywhere
  in this tree, unlike EtelPay's four separate connection sites).
- **`Database::insert()`/`update()`/`delete()`/`count()`** (`includes/database-new.php`)
  build their SQL from a `$table` argument and, for `update()`/`delete()`/`count()`, a
  `$where` string — every call site across all 12 files passes these as hardcoded string
  literals (table names, `'id = ?'`-style clauses), never from request input; the actual
  *values* filled into any placeholders in `$where` are always passed separately via
  `$params`/`$whereParams`, bound normally. Grepped every call site to confirm no
  exception.
- **`admin/api.php`'s `update_settings`** iterates `$_POST['settings']` as
  `$key => $value` and uses `$key` in a bound `WHERE setting_key = ?` placeholder and as a
  bound `INSERT` value — never concatenated into the SQL text itself, so an attacker
  cannot influence which column or table is touched by choosing an unusual settings key,
  only which row's `setting_value` in the fixed `settings` table gets written.
- **`api/index.php`'s `check_import`** builds a dynamic `IN (?,?,?...)` placeholder list
  (`$ph = implode(',', array_fill(0, count($unique), '?'))`) whose *length* depends on
  request input (how many plates were submitted) but whose *content* is always the
  literal character `?`, never attacker-controlled text — the actual plate values are
  bound normally via `array_merge(...)`. Same safe pattern already verified for EtelPay's
  `LIMIT` clause in the sibling audit, applied correctly here too.
- **`madeyaServedTodayConflict()`/`madeyaVehicleTypeAllowed()`/`madeyaParityViolation()`**
  (`includes/queue_logic.php`) and every controller/admin query touching `queue`/`report`/
  `controllers`/`vehicle_types` bind all request-derived values (`$plate`,
  `$vehicleTypeId`, `$controllerId`) as parameters — confirmed by reading every query in
  this file (the one most directly reachable by both the manual `register` action and the
  NFC auto-register endpoint).

### Needs Dagi's judgment call

None — no SQL injection surface needing a decision was found in any of the 12 files.

---

## 3. XSS / output escaping

**Checked:** every `innerHTML =`/`.html(`/`document.write`/`insertAdjacentHTML` sink
across `index.php`, `controller.php`, `admin/index.php`, and all four files under
`EtelMadeya/assets/js/` (`driver-app.js`, `controller-app.js`, `admin-app.js`,
`icons.js`) — 73 total sink occurrences found by grep; every one rendering a field that
can originate from a controller/admin/driver's free-text input (plate number, driver
name, station name, vehicle type name/icon, activity-log details) was individually
traced back to whether it's escaped before reaching `innerHTML`.

### Fixed — Item 1: `bulk_import` stored plate numbers without the same
`sanitize()`/`htmlspecialchars()` pass every other write path applies (High — stored
XSS reachable from a public, unauthenticated page)

**Finding:** the manual `register` action in `api/index.php` runs the submitted plate
through `sanitize($_POST['plate_number'] ?? '')` — `includes/config.php`'s `sanitize()`
is `htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8')` — before it ever reaches
`madeyaRegisterVehicle()`/the DB. `bulk_import`, the other write path into the same
`queue.plate_number` column, did not: `$plateArray =
array_filter(array_map('trim', explode("\n", $plates)))` only trimmed whitespace, so a
plate line containing `<script>...</script>` or an `<img onerror=...>` payload would be
stored verbatim. This matters because `driver-app.js`'s public, **unauthenticated**
`checkPosition()` handler (reachable by anyone from the homepage, no login required)
interpolated `v.plate_number` directly into `innerHTML` with no escaping at all (see Item
2 below) — so a plate injected via a controller's bulk-import textarea would execute as
script in the browser of any driver (or the general public) who later looked up that
exact plate on the public queue-position page. `check_import` (the *preview*/dry-run
action used right before `bulk_import`) does strip non-`[A-Z0-9\-]` characters from each
line for its duplicate-detection display — but that filtering is only applied to the
preview, never to what `bulk_import` itself actually inserts, so the two code paths could
diverge (a plate that looked "clean" in the check-import preview table was not
necessarily what actually got stored).

**Fix applied** in `EtelMadeya/api/index.php`'s `bulk_import`:
```php
$plateArray = array_filter(array_map(fn($p) => sanitize(trim($p)), explode("\n", $plates)));
```
Every bulk-imported plate now goes through the same `sanitize()` helper the manual
`register` action already used, closing the divergence between the two write paths into
`queue.plate_number`. Verified with `php -l` (no syntax errors) and the ASCII-byte scan
(no new non-ASCII bytes).

### Fixed — Item 2: the public driver-facing queue-position page rendered `plate_number`/
`station_name`/`vehicle_type_name`/`token` unescaped, unlike every other panel in this
codebase (Medium/High, defense-in-depth for Item 1 above)

**Finding:** `controller-app.js` and `admin-app.js` both consistently wrap every
free-text field (plate, station name, vehicle type name, driver full name, activity-log
detail) in an `escHtml()` helper before interpolating it into `innerHTML` — confirmed by
reading every one of the ~50 render sites across both files (e.g.
`controller-app.js`'s `displayQueue()` at line 522, `admin-app.js`'s
`get_dashboard_stats`/`get_controllers`/`get_queue_monitor` render functions). But
`driver-app.js`'s `checkPosition()` handler (lines 153–174, the public, unauthenticated
plate-lookup page) and `renderDriverQueue()` (lines 239–301, the public active-queue view
shown right below it) interpolated `v.plate_number`, `v.station_name`,
`v.vehicle_type_name`, `v.vehicle_type_name_am`, `v.token`, and `v.queue_type` directly
into template literals with no `escHtml()` call at all, despite this same file already
defining and using `escHtml()` elsewhere (line 327, used correctly at line 182 for the
"vehicle not found" error message). Given the `bulk_import` gap in Item 1, this was a
second, independent layer that also needed closing — even with Item 1 fixed, any future
write path into `queue.plate_number`/`controllers.station_name`/`vehicle_types.name` that
forgets to sanitize would have been immediately exploitable here with no second line of
defense, unlike the controller/admin panels which already had one.

**Fix applied** in `EtelMadeya/assets/js/driver-app.js`: every occurrence of
`v.plate_number`/`v.station_name`/`v.vehicle_type_name`/`v.vehicle_type_name_am`/
`v.token`/`v.queue_type`/`v.display_position`/`v.icon` in `checkPosition()` and
`renderDriverQueue()` now goes through the file's own existing `escHtml()` helper,
matching the pattern already used everywhere in `controller-app.js`/`admin-app.js`.
No `php -l` applicable (JS file); manually verified brace/backtick balance and that
`escHtml` (a hoisted `function` declaration) is safely callable before its textual
definition later in the same file.

### Verified clean

- **`controller-app.js`** — every queue-table render site (`displayQueue()`,
  `loadWaitingQueue()`, the VIP-tab renderer, the report-tab renderer, the
  import/duplicate-check renderer) wraps `plate_number`/`vehicle_type_name`/`token`/
  `icon`/`station_name` in `escHtml()`. The `onclick="showServeModal(${v.id},
  ${jsStr(v.plate_number)}, ...)"` pattern additionally uses a dedicated `jsStr()` helper
  (line 541) that backslash-escapes both `\` and `'` before embedding a value inside a
  single-quoted JS string literal within a double-quoted HTML attribute — the same
  defense-in-depth JS-string-hardening pattern already verified for Delivery & Ride's and
  EtelPay's admin panels in the sibling audits.
- **`admin-app.js`** — every one of its ~30 `escHtml()` call sites was checked; dashboard
  activity log (`full_name`, `action`), controller table (`username`, `full_name`,
  `station_name`, `phone`), vehicle-type table (`icon`, `name`, `name_am`), queue monitor
  (`station_name`, `plate_number`, `vehicle_type_name`), and the reports table
  (`plate_number`, `vehicle_type_name`, `station_name`) are all escaped at their actual
  render site, not relied upon anywhere upstream.
- **`showNotification()`'s raw `innerHTML = message` assignment** exists in all three JS
  files (`driver-app.js` line 333, and equivalents in the other two) — checked every call
  site across the codebase: in `controller-app.js`/`admin-app.js` every caller already
  wraps any server-supplied `data.message` in `escHtml()` before passing it in (e.g.
  `showNotification(ICONS.error + ' ' + escHtml(data.message || '...'), 'error')`, grepped
  consistently). In `driver-app.js`, `showNotification()` is defined but has **zero call
  sites anywhere in the file** (grepped) — dead code, not a live XSS vector today, but
  flagged below since it would need the same `escHtml()` treatment as the other two files
  if it's ever wired up.
- **`index.php`/`admin/index.php`/`controller.php`** — the only server-side PHP
  interpolation into these three page shells is `SITE_PHONE`/`SITE_EMAIL`/`SITE_ADDRESS`/
  `SITE_HOURS`/`SITE_NAME` (all hardcoded constants in `includes/config.php`, never
  request-influenced) and `date('Y')`/`date('Y-m-d')` — no request-supplied value is ever
  echoed into any of these three pages' HTML.

### Needs Dagi's judgment call

- **`driver-app.js`'s `showNotification()` is dead code today** (Section 3, "Verified
  clean" above) but uses the same unescaped-`innerHTML` pattern that would need
  `escHtml()` wrapping at every future call site if this function is ever wired up on the
  public driver pages. Not changed further here (nothing to fix in a function nothing
  calls), but noted so a future caller doesn't assume it's already safe the way the other
  two files' equivalents are.

---

## 4. Session/cookie security

**Checked:** `includes/config.php`'s `session_start()` call and every session-related
setting in `.htaccess`; `includes/security.php`'s `secureSession()`,
`isControllerAuthenticated()`/`isAdminAuthenticated()`, `requireControllerAuth()`/
`requireAdminAuth()`; the `controller_etelid_login`/`admin_etelid_login`/`*_logout`
lifecycle in both routers.

### Findings

**None requiring a code fix.**

### Verified clean

- **Session cookie flags are set at the web-server level, not left to PHP defaults.**
  `.htaccess` sets `php_value session.cookie_httponly 1` and
  `php_value session.use_strict_mode 1` — the cookie is inaccessible to JavaScript
  (mitigating cookie theft via any XSS that might otherwise occur) and PHP will not
  accept an uninitialized session ID supplied by the client (session fixation
  protection).
- **Session ID regeneration on login.** Both `controller_etelid_login` and
  `admin_etelid_login` call `session_regenerate_id(true)` immediately before setting any
  session variables (`api/index.php` line 286, `admin/api.php` line 120) — a session ID
  that existed before authentication cannot be reused post-login, closing the classic
  session-fixation gap.
- **Idle-timeout enforcement runs on every request, not just at login.**
  `secureSession()` (`includes/security.php`, called unconditionally at the bottom of
  every request via `includes/config.php`'s `require_once`) destroys the session outright
  if `time() - $_SESSION['last_activity'] > SESSION_TIMEOUT` (8 hours, `config.php`
  line 49) — a stale session is actively killed server-side, not merely left to expire on
  its own. It also periodically calls `session_regenerate_id(true)` every 30 minutes of
  continued activity (line 223–226), limiting how long any single session ID stays valid
  even for an actively-used session.
- **Logout genuinely destroys server-side session state.** Both `controller_logout` and
  `admin/api.php`'s `logout` call `session_destroy()` (not merely unsetting a couple of
  keys) — a session ID that was logged out cannot be reused to regain access even if the
  cookie is somehow replayed.
- **No cross-account privilege confusion between the two session types.**
  `requireControllerAuth()` checks `$_SESSION['controller_id']` only,
  `requireAdminAuth()` checks `$_SESSION['admin_id']` only — grepped every
  `requireControllerAuth`/`requireAdminAuth` call site in both routers and confirmed
  neither ever accepts the other's session key as equivalent, so a controller session
  cannot be used to call an admin-only action or vice versa.

### Needs Dagi's judgment call

- **`.htaccess` sets `php_value session.cookie_secure 0`** — the session cookie is not
  marked `Secure`, so it would still be sent over a plain-HTTP connection if the site
  were ever reachable that way. `includes/config.php`'s `SITE_URL` is
  `https://ethelmarket.com/Etel-Madeya`, and the `.htaccess`'s HTTP→HTTPS redirect block
  is present but commented out (lines 8–10: *"uncomment for production with SSL"*) — so
  whether this matters in practice depends entirely on whether the live cPanel vhost
  itself forces HTTPS (e.g. via a host-level redirect, separate from this app's own
  `.htaccess`) or genuinely still accepts plain HTTP. Not changed here since flipping
  `cookie_secure` to `1` on a host that does *not* reliably terminate everything at HTTPS
  would break sessions outright for any visitor who somehow reaches the app over HTTP —
  a live-infrastructure question only Dagi can answer, not something to guess at from the
  code alone.
- **No idle/inactivity distinction beyond the flat 8-hour `SESSION_TIMEOUT`** — this is
  actually a *shorter*, more conservative window than EtelPay's flat 7-day bearer-token
  TTL (flagged as a judgment call in that sibling audit) or EtelID's own 8-hour SSO
  cookie idle cutoff (per the shared ops notes) — so no change is suggested here; noted
  only for completeness since it's the same category of setting reviewed in every other
  system's audit.

---

## 5. Error handling and logging sweep

**Checked:** all 12 PHP files for `catch` blocks with an empty or comment-only body, any
code path that could leak sensitive internal state (DB credentials, stack traces, secret
fragments) into an HTTP response, and whether any diagnostic/debug endpoint exists
unauthenticated (the exact class of bug already found in EtelPay's `ep_diag_*` actions in
the sibling audit).

### Fixed — `'Server error: ' . $e->getMessage()` (and two narrower equivalents) echoed
raw exception text, including database error strings, to any caller (Medium —
information disclosure)

**Finding:** both routers' top-level `catch` blocks, plus two inner `catch` blocks inside
`api/index.php`'s `serve_vehicle` and `bulk_import` (both of which run inside their own
DB transaction), built the client-facing error message by directly concatenating
`$e->getMessage()`:
```php
jsonResponse(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
jsonResponse(['success' => false, 'message' => 'Failed to serve vehicle: ' . $e->getMessage()], 500);
jsonResponse(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()], 500);
```
A `PDOException` bubbling up from `Database::query()` on any real failure (a bad foreign
key, a duplicate-key violation, a transient connection drop) carries the underlying
driver's exact error text — this can include the SQL fragment that failed, table/column
names not otherwise exposed by the API's own responses, or (in less common cases) DSN
details. Combined with `includes/config.php`'s `ini_set('display_errors', 1)` (see
"Needs Dagi's judgment call" below), this meant a caller triggering *any* unhandled
exception, not just an intentionally-returned validation error, could learn real
internal schema/query details for free.

**Fix applied**: all three sites in `EtelMadeya/api/index.php`, and the equivalent one in
`EtelMadeya/admin/api.php`, now log the real exception via the existing `error_log()`
call (already present at the top-level catch in both files; added to the two inner
`serve_vehicle`/`bulk_import` catches, which previously logged nothing at all before this
pass) and return a fixed, generic message to the client instead:
```php
error_log("serve_vehicle failed: " . $e->getMessage());
jsonResponse(['success' => false, 'message' => 'Failed to serve vehicle. Please try again.'], 500);
```
Matches the same fix shape already applied to EtelPay's `etelid_members_list.php` debug
leak and Delivery & Ride's `log_updater_action()` gap in the two sibling audits — log the
detail server-side where only Dagi can read it, return a generic message to the caller.
Verified with `php -l` (no syntax errors) on both files and the ASCII-byte scan (no new
non-ASCII bytes).

### Verified clean

- **No unauthenticated diagnostic/debug endpoint exists anywhere in this codebase.**
  Grepped both routers' full action lists (Section 8 below) for anything resembling
  EtelPay's `ep_diag_db`/`ep_diag_sessions`/`ep_diag_telebirr` — none found. This
  codebase has no diagnostic action at all, gated or otherwise.
- **`Database`'s own connection/query failure handling never echoes driver-level detail
  to the client.** `includes/database-new.php`'s constructor `catch` block calls
  `error_log("Database connection failed: " . $e->getMessage())` then `die("Database
  connection failed. Please check configuration.")` — a generic message, not
  `$e->getMessage()`, reaches the response either way (this path predates and is separate
  from the three call-site leaks fixed above, which occurred one layer up, in the
  routers' own `catch` blocks around otherwise-successful `Database` calls).
- **`logSecurityEvent()`/`detectSQLInjection()`/`preventDirectoryTraversal()`**
  (`includes/security.php`) all write to `logs/security.log` (created on first use,
  `0755` directory permissions) rather than ever surfacing detection results back to the
  client — confirmed no caller of `detectSQLInjection()` exists that echoes the matched
  pattern or input back in a response (in fact, `detectSQLInjection()` itself is defined
  but has **zero call sites** anywhere in the codebase — see "Needs Dagi's judgment call"
  below).
- **No `catch` block anywhere in the 12 audited files has a genuinely empty body** —
  every `catch` either calls `error_log()` (after this pass's fix, consistently) or lets
  the exception propagate to the top-level handler, which itself always logs before
  responding.

### Needs Dagi's judgment call

- **`includes/config.php` sets `ini_set('display_errors', 1)` and
  `error_reporting(E_ALL)` unconditionally** (lines 76–77), with no environment flag
  (dev/staging/production) anywhere in this codebase to gate it — every other Etel Group
  system audited so far keeps this the same way (a single hardcoded config file, no
  environment abstraction), so this isn't a departure from the codebase's own
  conventions, but on a genuine production host it means a raw, unhandled PHP
  warning/notice/fatal (as opposed to the caught-`Exception` case fixed above, which is
  now generic) could still print a stack trace with real file paths directly into an
  HTTP response before any of this file's own JSON-wrapping logic gets a chance to run.
  Not changed here: flipping this to `0` outright would also silence errors Dagi may
  currently be relying on seeing during ongoing development of this system, and there's
  no established per-environment toggle pattern in this codebase to build on safely
  without guessing at how Dagi wants staging vs. production distinguished.
- **`detectSQLInjection()` (`includes/security.php`) is defined but never called anywhere
  in the codebase** — a pattern-matching pre-filter that would `logSecurityEvent()` and
  return `true` on inputs resembling `UNION SELECT`, `DROP TABLE`, etc., but nothing in
  either router invokes it. Since every actual query in this codebase already uses bound
  parameters (Section 2), this function's absence from the request path is not itself a
  vulnerability — real SQL injection defense here is the prepared-statement discipline,
  not this heuristic filter. Left as-is rather than wiring it in, since doing so now
  would add a second, redundant, pattern-based layer with its own false-positive risk
  (e.g. a legitimate driver name containing the word "select") for no real security gain
  given the parameterization is already complete; flagged only because dead security
  code sitting unused in a shared file is worth knowing about explicitly rather than
  silently passing over.

---

## 6. Input validation audit

**Checked:** every action across both routers for how request values are validated
before reaching SQL, a session write, or the queue's rationing logic (vehicle-type
allow-list, odd/even parity, cross-station served-today conflict) — particular focus on
`register`/`bulk_import`'s plate handling (already covered under Section 3's XSS finding)
and the queue-registration race-condition concern the task brief specifically raised.

### Fixed — `madeyaRegisterVehicle()`'s duplicate-plate check and its own insert were
not atomic, allowing two concurrent registrations of the same plate to both succeed
(Medium — race condition, matches the task brief's specific concern)

**Finding:** `includes/queue_logic.php`'s `madeyaRegisterVehicle()` — the single shared
function both the manual `register` action (`api/index.php`) and the NFC auto-register
endpoint (`api/service_nfc_register.php`) call — checked for an existing queued row for
the same plate with a plain `SELECT id FROM queue WHERE plate_number = ? AND status =
'queued'`, then, if none was found, ran a separate `INSERT`. Nothing in the database
schema enforces uniqueness on `plate_number` for queued rows either: `queue.status` is an
`ENUM('queued','serving','served','cancelled')` (checked `database.sql`), so a flat
`UNIQUE KEY` on `plate_number` alone isn't viable (a plate legitimately gets a new
`queued` row after being served and removed, or after being cancelled) — there is no
partial/filtered unique index in MySQL to enforce "unique only while status='queued'"
declaratively the way EtelPay's `ep_partner_credit`/`ep_platform_transfer` idempotency
keys could use a real `UNIQUE KEY` (per the sibling audit). This meant two nearly
simultaneous registrations of the same plate — the exact scenario the task brief raised:
two different drivers' NFC cards somehow resolving to the same plate, or a manual
controller registration racing an NFC auto-register for a driver who was just registered
by phone — could both read "not currently queued" before either had inserted its row,
producing two queue entries (two tokens, two positions) for one vehicle, defeating the
whole point of the rationing guards this function exists to enforce.

**Fix applied** in `EtelMadeya/includes/queue_logic.php`'s `madeyaRegisterVehicle()`:
wrapped the duplicate-plate check, the served-today check, and the insert in a single
database transaction, with the duplicate check upgraded to `SELECT id FROM queue WHERE
plate_number = ? AND status = 'queued' FOR UPDATE`. On InnoDB (this schema's storage
engine, confirmed in `database.sql`), a `SELECT ... FOR UPDATE` against an indexed column
(`idx_plate` already exists on `queue.plate_number`) takes a lock on that value's index
range for the duration of the transaction — a second, concurrent call for the *same*
plate blocks on its own `FOR UPDATE` until the first transaction commits or rolls back,
so the two calls are serialized instead of both passing the check. Every early-return
branch inside the guarded section now explicitly `commit()`s before returning (nothing to
undo, but keeping the transaction lifecycle symmetric), and any exception inside is
caught, rolled back, and re-thrown so the existing top-level `catch` in both call sites
(`api/index.php`'s `register` action and `service_nfc_register.php`) continues to handle
it exactly as before. Verified with `php -l` (no syntax errors) and the ASCII-byte scan
(no new non-ASCII bytes).

### Verified clean

- **The three rationing guards run in a fixed, deliberate order** (vehicle-type
  allow-list → parity → served-today), matching the order documented in `api/index.php`'s
  own header comment as ported from the original WordPress plugin's
  `madeya_ajax_register()` — confirmed both `madeyaRegisterVehicle()` (manual + NFC path)
  and `bulk_import`'s own inline loop apply the same three checks (bulk_import
  additionally tracks `skippedParity`/`skippedServed` counts for its summary message).
- **Plate/phone normalization is applied consistently at every comparison site.**
  `madeyaNormalizePhone()`/`etelid_bridge_normalize_phone()` (last-9-digit matching, same
  convention documented in the shared Etel Group ops notes and already verified for
  EtelPay/Delivery in the sibling audits) is used by every phone-based lookup in this
  codebase: `madeyaFindControllerByPhone()`, `madeyaFindAdminByPhone()`,
  `madeyaRememberDriverVehicle()`, and both EtelID-SSO login flows.
- **`validatePlateNumber()`/`validatePhone()`/`validateEmail()`** (`includes/security.php`)
  exist and are correctly shaped (Ethiopian plate/phone formats), but are **not actually
  called anywhere in either router** — `register`/`bulk_import`/`create_controller`
  accept any non-empty string for a plate or phone rather than enforcing these formats
  server-side. This is not a security vulnerability on its own (every value that reaches
  SQL is still bound as a parameter regardless of format, and every value that reaches
  HTML is now — after Section 3's fixes — consistently escaped), so it's a data-quality
  gap rather than an exploitable one; not changed here, see "Needs Dagi's judgment call"
  below.
- **`get_report`/`check_import`'s date handling** validates the date format with
  `preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)` before use, falling back to today's date
  if the supplied value doesn't match — confirmed in `api/index.php`'s `get_report`.
- **File upload validation** (`includes/config.php`'s `uploadImage()`) checks
  `UPLOAD_ERR_OK`, a 5MB size cap, and an extension allow-list
  (`jpg`/`jpeg`/`png`/`webp`/`gif`) before calling `move_uploaded_file()` — though this
  function is defined but, like `detectSQLInjection()`, has **zero call sites** anywhere
  in either router (no action in this codebase currently accepts a file upload at all) —
  dead code today, not a live vector.

### Needs Dagi's judgment call

- **`validatePlateNumber()`/`validatePhone()` are defined but never enforced** at any
  actual registration/controller-creation call site (above) — whether plate/phone format
  should be strictly enforced (rejecting malformed input outright) or left permissive (as
  it is today, which tolerates real-world messiness like plates typed with extra spaces
  or non-standard formats a controller might see on an older vehicle) is a product
  decision, not a security one given the rest of the pipeline already treats these values
  safely regardless of format. Not changed.
- **`uploadImage()`/`ALLOWED_EXTENSIONS`/`MAX_UPLOAD_SIZE` are fully dead code** — no
  action anywhere accepts a file upload. If a future feature (e.g. a vehicle-type icon
  upload, or an activity-log photo) is ever added, this existing helper already has
  reasonable validation to build on; noted only so it isn't mistaken for a currently-live,
  already-audited upload path.

---

## 7. Known open items — confirmed current status

### 7.1 `backups/` directory and other unprotected sensitive paths

**Checked:** the entire `EtelMadeya/` tree (`find EtelMadeya -type f`) for any
`backups/`-style directory, `.env` file, or other checked-in-but-web-accessible sensitive
path — the same class of gap already found and fixed in both Delivery & Ride's
`api/backups/` and EtelPay's `wallet/backups/` in the sibling audits.

**Found:** no `backups/` directory exists anywhere in this codebase (this system has no
in-app file-deploy/Updater tool the way EtelID/EtelPay/Delivery do, so there's no
`upd_backup()`-equivalent function that would create one either) — confirmed by a full
`find` across the tree. **No fix needed here**, unlike the two sibling systems.

**Found instead — a related but distinct gap:** `EtelMadeya/error_log` and
`EtelMadeya/admin/error_log` are PHP's default error-log files (written automatically by
`error_log()` calls and any raw PHP warning, whenever `log_errors` is on and no explicit
`error_log` ini path is set — confirmed both files exist and contain real entries, e.g.
`"Database connection failed: SQLSTATE[HY000] [1045] Access denied for user
'root'@'localhost' (using password: NO)"`, from a local dev/testing session). The root
`.htaccess`'s existing sensitive-file block, `<FilesMatch "\.(sql|log|md|txt|json|bak|
old)$">`, only matches filenames *ending in a literal `.log` extension* — it does **not**
match a file literally named `error_log` (no `.` before `log`), so neither file was
denied by this rule. Nothing else in `.htaccess` covered them either. A direct request to
`https://ethelmarket.com/Etel-Madeya/error_log` or `.../admin/error_log` was not
explicitly denied before this pass — while the content seen in this repo checkout is a
local dev artifact (a DB-connection failure with a username but not a password), the
underlying gap is real and would apply equally to any future entries these files
accumulate on the live host, including SQL error text (`includes/database-new.php`'s
`query()` method logs `"Query failed: " . $e->getMessage() . " | SQL: " . $sql`) that
could reveal schema/query detail beyond what Section 5's fix already keeps out of HTTP
responses.

**Fixed:** added an explicit `<Files "error_log"> ... Deny from all </Files>` block to
`EtelMadeya/.htaccess`, alongside the existing extension-based rule. Apache applies a
parent directory's `.htaccess` rules to subdirectories unless overridden by a closer one
— confirmed no other `.htaccess` exists anywhere under `EtelMadeya/admin/` (or anywhere
else in the tree) that could override this, so the single root-level rule covers both
`EtelMadeya/error_log` and `EtelMadeya/admin/error_log` in one place. Verified the edited
`.htaccess` still parses (`php -l` on an `.htaccess` file simply confirms it isn't
mistaken for embedded PHP — used here only as a basic file-integrity sanity check since
there's no Apache binary in this sandbox to fully validate the directive syntax against;
the added block matches the exact same `<Files>`/`Order allow,deny`/`Deny from all` shape
already used correctly elsewhere in this same file for `config|database|security.php` and
hidden files).

### 7.2 Hardcoded/placeholder secrets

**Checked:** `includes/config.php`'s one shipped placeholder secret
(`ETELID_SERVICE_KEY`, `'CHANGE_ME'`) and every place it's actually consumed.

**Found — one real placeholder-acceptance bug in the pre-existing bridge, matching the
exact pattern already fixed in the two NFC endpoints added this session:**
`includes/etelid_bridge.php`'s `etelid_bridge_service_key()` (the function every outbound
call to EtelID — `etelid_bridge_authenticate()`, `etelid_bridge_check_role()` — goes
through) rejected the placeholder with a **case-sensitive** exact-string comparison:
`ETELID_SERVICE_KEY !== 'CHANGE_ME'`. Because `config.php` ships the literal placeholder
in that exact case, this happened to correctly reject the shipped default as-is — but
`api/service_nfc_register.php`/`api/service_nfc_check_role.php` (built later, this
session) both use a **case-insensitive** check instead
(`strcasecmp(trim((string) ETELID_SERVICE_KEY), 'CHANGE_ME') === 0`), the same pattern
EtelPay's `ep_is_placeholder()` and `service_check_wallet.php`/`service_nfc_charge.php`
use. The inconsistency meant `etelid_bridge.php` was one accidental deploy-time edit away
from a bypass — if an operator, believing they were "changing" the placeholder, set the
constant to `'change_me'` or `'Change_Me'` (a natural typo when hand-editing a
config file) instead of the exact-cased literal, `etelid_bridge_service_key()` would
treat that value as a real, configured secret and send it as the outbound
`X-Etel-Service-Key` on every call to EtelID — silently sending a well-known,
effectively-placeholder string as if it were the real shared secret, with no error or
warning. This wouldn't grant an attacker anything directly (it only affects *outbound*
calls this system makes *to* EtelID, not what this system accepts as valid *inbound*
service-key auth — that's handled separately, and correctly, by the two NFC endpoints'
own case-insensitive checks), but it is the exact same fragile-placeholder-check shape
flagged as a real, fixed bug in both EtelID's own `ETELID_SERVICE_KEY_OLD` handling
pattern and EtelPay's `EP_UPDATER_PASS` bug in the sibling audit.

**Fix applied** in `EtelMadeya/includes/etelid_bridge.php`'s `etelid_bridge_service_key()`:
```php
function etelid_bridge_service_key() {
    if (defined('ETELID_SERVICE_KEY') && ETELID_SERVICE_KEY && strcasecmp(trim((string) ETELID_SERVICE_KEY), 'CHANGE_ME') !== 0) {
        return ETELID_SERVICE_KEY;
    }
    $k = getenv('ETELID_SERVICE_KEY');
    return $k ? $k : '';
}
```
Now matches the case-insensitive, trimmed comparison already used consistently by both
NFC service endpoints. Verified with `php -l` (no syntax errors) and the ASCII-byte scan
(no new non-ASCII bytes).

**No other placeholder-secret gap found.** `DB_USER`/`DB_PASS` in `config.php` ship as
`'root'`/`''` (empty) with an inline comment instructing the operator to change them for
the live server — there's no placeholder-*rejection* logic to check here the way
`ETELID_SERVICE_KEY`/EtelPay's `EP_SECRET`/`EP_UPDATER_PASS` have, because a DB connection
either works or `Database`'s constructor fails closed with a generic error (Section 5) —
an empty/wrong DB password cannot silently "look configured" the way a placeholder string
comparison can.

---

## 8. Full API endpoint inventory

### 8.1 `api/index.php` — driver & controller actions (dispatched by `$_GET['action']`/`$_POST['action']`)

| Action | Access Level |
|---|---|
| `check_position` | Public (PII-trimming fix applied this pass) |
| `register` | Public (self-service registration by any driver/kiosk, by design) |
| `get_vehicle_types` | Public (flags `allowed` per-type only when a controller session exists) |
| `get_controllers` | Public |
| `driver_active_queue` | Public |
| `controller_login` | Public (always 403 — local login retired) |
| `controller_etelid_login` | Public (EtelID SSO exchange, rate-limited — see Section 1 judgment call) |
| `controller_logout` | Public (no-op cleanly with no session) |
| `check_session` | Public |
| `get_queue` | Authenticated (controller, scoped to own station) |
| `serve_vehicle` | Authenticated (scoped to own station — fixed this pass) |
| `move_to_vip` | Authenticated (scoped to own station — fixed this pass) |
| `move_to_waiting` | Authenticated (scoped to own station — fixed this pass) |
| `bulk_import` | Authenticated (scoped to own station; plates now sanitized — fixed this pass) |
| `clear_queue` | Authenticated (scoped to own station) |
| `get_stats` | Authenticated (scoped to own station) |
| `check_import` | Authenticated (scoped to own station) |
| `get_report` | Authenticated (scoped to own station) |

### 8.2 `api/service_nfc_register.php` / `api/service_nfc_check_role.php` — standalone, bypass the router entirely

| File | Access Level |
|---|---|
| `service_nfc_register.php` | Service key (`X-Etel-Service-Key` header or `?key=`) |
| `service_nfc_check_role.php` | Service key |

### 8.3 `admin/api.php` — admin panel actions

| Action | Access Level |
|---|---|
| `login` | Public (always 403 — local login retired) |
| `admin_etelid_login` | Public (EtelID SSO exchange, rate-limited, restricted to `admin` EtelGroup role only) |
| `logout` | Authenticated (admin) |
| `check_session` | Public |
| `get_dashboard_stats` | Authenticated (admin) |
| `get_served_trend` | Authenticated (admin) |
| `get_fuel_snapshot` | Authenticated (admin) |
| `get_controllers` | Authenticated (admin) |
| `create_controller` | Authenticated (admin) |
| `update_controller` | Authenticated (admin) |
| `delete_controller` | Authenticated (admin) |
| `get_vehicle_types` | Authenticated (admin) |
| `create_vehicle_type` | Authenticated (admin) |
| `update_vehicle_type` | Authenticated (admin) |
| `delete_vehicle_type` | Authenticated (admin) |
| `get_queue_monitor` | Authenticated (admin) |
| `get_reports` | Authenticated (admin) |
| `get_settings` | Authenticated (admin) |
| `update_settings` | Authenticated (admin) |

**Grand total: 18 driver/controller actions + 2 standalone NFC service scripts + 19 admin
actions = 39 distinct entry points**, none behind a `boot.php`-style separate whitelist —
the `switch` statement itself is the entire access-control surface for each router, so
every `case` above was individually inspected rather than trusted from a table of
contents.

---

## 9. Sample request/response pairs

All based directly on the actual action code (field names/types exactly as read from
`$_GET`/`$_POST`/`sanitize()`, returned via `jsonResponse()`, which always wraps a
response as `{"success": true|false, ...}` with the given HTTP status code, default
`200`). Placeholder values below are fictitious — no real customer data.

### 9.1 Public plate lookup — `check_position`
*(`api/index.php`, no auth required)*

**Request:**
```
GET api/index.php?action=check_position&plate=AA-3-12345
```

**Success response** *(after this pass's fix — no `driver_name`/`driver_phone`):*
```json
{
  "success": true,
  "vehicle": {
    "id": 4821,
    "controller_id": 3,
    "vehicle_type_id": 2,
    "plate_number": "AA-3-12345",
    "plate_parity": "odd",
    "token": "7F2C9A1B",
    "position": 12,
    "queue_type": "normal",
    "status": "queued",
    "batch_id": 1,
    "registered_at": "2026-09-21 07:15:03",
    "vehicle_type_name": "Taxi",
    "vehicle_type_name_am": "ታክሳ",
    "icon": "🚕",
    "station_name": "Etel Madeya Gas Station",
    "station_name_am": "Etel Madeya Gas Station",
    "ahead": 11,
    "alert": true
  }
}
```

### 9.2 Controller serves a vehicle — `serve_vehicle`
*(`api/index.php`, requires an authenticated controller session; scoped to the
caller's own station after this pass's fix)*

**Request:**
```
POST api/index.php?action=serve_vehicle
Content-Type: application/x-www-form-urlencoded

queue_id=4821&fuel_gallons=10.5&fuel_liters=0
```

**Success response:**
```json
{ "success": true, "message": "Vehicle served successfully" }
```
*(A `queue_id` belonging to a different station now returns `{"success": false, "message":
"Vehicle not found"}` instead of serving it, per this pass's fix.)*

### 9.3 Controller EtelID SSO login — `controller_etelid_login`
*(`api/index.php`, public, rate-limited by IP — see Section 1 judgment call on the
rate limiter's own bypassability)*

**Request:**
```
POST api/index.php?action=controller_etelid_login
Content-Type: application/x-www-form-urlencoded

identifier=%2B251911000000&password=SecretPass123
```

**Success response:**
```json
{
  "success": true,
  "controller": {
    "id": 3,
    "username": "eid_251911000000",
    "full_name": "Test Controller",
    "phone": "+251911000000",
    "station_name": "Etel Madeya Gas Station",
    "staff_role": "Dispatcher",
    "is_etelgroup_managed": 1,
    "is_active": 1
  },
  "sso": true
}
```
*(`controller.password` is unset before the response is sent, per
`unset($controller['password']);` — confirmed present. Fails with 403 "No controller
account found for this EtelID" if neither an existing `controllers` row nor an
`etel-madeya` EtelGroup role assignment exists for this phone.)*

### 9.4 NFC tap-to-register (server-to-server) — `service_nfc_register.php`
*(standalone script, not through `api/index.php`; requires
`X-Etel-Service-Key: <ETELID_SERVICE_KEY>`; called by EtelID only after the
controller/admin's own EtelID login verifies on EtelID's own side)*

**Request** (POST `api/service_nfc_register.php`):
```json
{
  "controller_phone": "+251911000000",
  "driver_phone": "+251922000000",
  "driver_etel_id": "EG-2026-00001"
}
```

**Success response:**
```json
{
  "success": true,
  "data": {
    "token": "7F2C9A1B",
    "position": 13,
    "plate_number": "AA-3-12345",
    "station_name": "Etel Madeya Gas Station",
    "message": "Registered AA-3-12345 - token 7F2C9A1B, position #13 at Etel Madeya Gas Station."
  }
}
```
*(Fails with 404 if no `driver_vehicles` row exists yet for this driver phone — the
controller must manually register that driver's plate once, with their phone number, to
seed the association. Fails with 403 if `controller_phone` matches neither an active
controller nor an admin/manager. If it matches an admin/manager with no single station of
their own, the response instead returns `{"success": true, "data": {"needs_station":
true, "stations": [...]}}}`, and the caller must retry with a `station_id`.)*

---

## Summary of code changes made in this pass

| File | Change |
|---|---|
| `EtelMadeya/api/index.php` | Scoped `serve_vehicle`/`move_to_vip`/`move_to_waiting` to the caller's own `controller_id` (were fully cross-station IDOR); trimmed `check_position`'s `SELECT q.*` to an explicit column list that excludes `driver_name`/`driver_phone` (unused PII leak on a public endpoint); `bulk_import` now runs each plate through `sanitize()` (was storing raw, unescaped input — stored-XSS write path); replaced three `... . $e->getMessage()` client-facing error messages with generic messages + `error_log()` |
| `EtelMadeya/admin/api.php` | Replaced the top-level `'Server error: ' . $e->getMessage()` with a generic message (kept the existing `error_log()` call) |
| `EtelMadeya/includes/queue_logic.php` | `madeyaRegisterVehicle()`'s duplicate-plate check and insert are now wrapped in a transaction with `SELECT ... FOR UPDATE`, closing a real race condition between two concurrent registrations of the same plate |
| `EtelMadeya/includes/etelid_bridge.php` | `etelid_bridge_service_key()`'s placeholder rejection is now case-insensitive (`strcasecmp`), matching the pattern already used by both NFC service endpoints |
| `EtelMadeya/assets/js/driver-app.js` | `checkPosition()` and `renderDriverQueue()` (the public driver-facing pages) now escape `plate_number`/`station_name`/`vehicle_type_name`/`token`/etc. via the file's own existing `escHtml()` helper, matching the pattern already used consistently in `controller-app.js`/`admin-app.js` |
| `EtelMadeya/.htaccess` | Added an explicit `<Files "error_log">` deny rule — the existing `\.(sql|log|...)$ ` pattern only matched a trailing `.log` extension and did not cover the literally-named `error_log`/`admin/error_log` files, which contained real DB-connection-failure detail |

All PHP edits verified with `php -l` (no syntax errors) and the ASCII-safety check (no
new non-ASCII bytes introduced by any edit — this codebase's pre-existing box-drawing
header comments and one pre-existing em-dash were left untouched and are not a violation
of the ASCII discipline applied to everything newly written in this pass). Nothing was
deployed — all changes are in this repo's git working tree only; deployment to
`ethelmarket.com/Etel-Madeya/` is a separate manual step handled by Dagi.

## Consolidated list of items needing Dagi's judgment call

1. **`checkRateLimit()`'s per-endpoint brute-force throttle is stored in `$_SESSION`, so
   it's trivially bypassed by not sending a session cookie** (Section 1) — the real
   password check happens server-to-server against EtelID, so the actual exposure
   depends on protections in EtelID's own Service API (outside this audit's scope). A
   real fix needs a durable, session-independent store (a new DB table), which is a
   schema decision this pass didn't make unilaterally. Not changed.
2. **`admin_users.role` has no enforced per-action capability tiers** (Section 1) — every
   admin has the same full capability set today; may be entirely intentional for a
   system this size. Not changed.
3. **`driver-app.js`'s `showNotification()` is dead code using the same unescaped-
   `innerHTML` pattern that was fixed elsewhere in this file** (Section 3) — not
   currently exploitable since nothing calls it, but would need the same `escHtml()`
   treatment if ever wired up. Not changed.
4. **`session.cookie_secure 0` in `.htaccess`** (Section 4) — depends on whether the live
   cPanel vhost reliably forces HTTPS at the infrastructure level; flipping this without
   confirming that could break sessions for anyone who reaches the app over plain HTTP.
   Not changed.
5. **`ini_set('display_errors', 1)` / `error_reporting(E_ALL)` unconditional in
   `config.php`** (Section 5) — matches this codebase's existing no-environment-flag
   convention; disabling it outright could silence errors Dagi currently relies on
   seeing, and there's no established per-environment toggle to build on. Not changed.
6. **`detectSQLInjection()` is defined but never called anywhere** (Section 5) — not a
   live gap given every query is already parameterized, but flagged as unused security
   code sitting in a shared file. Not changed.
7. **`validatePlateNumber()`/`validatePhone()` are defined but never enforced** at any
   registration/controller-creation call site (Section 6) — a data-quality question, not
   a security one, since values are safely bound/escaped downstream regardless of
   format. Not changed.
8. **`uploadImage()` and its validation are fully dead code** — no action anywhere
   currently accepts a file upload (Section 6). Noted for a future feature, not a gap in
   anything live today. Not changed.
