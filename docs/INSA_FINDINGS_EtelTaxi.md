# INSA Pre-Submission Security Findings — EtelTaxi

First-time INSA pre-submission audit of EtelTaxi (Etel Group's taxi/ride association-dues
and driver compliance system, branded "Etel Transport" internally — canonical slug
`etel-transport` in EtelID's Employees panel), living at `EtelTaxi/` in this repo, deployed
at `ethelmarket.com/EtelTaxi/`. No prior audit document existed for this system — this file
is written from scratch. Scope covers all 15 PHP files (`api/boot.php`, `api/config.php`,
`api/controllers/{Admin,Driver,Officer,Service}Controller.php`,
`api/lib/{webpush,taxi_payments,taxi_push,etelid_bridge}.php`, `api/migrate.php`,
`api/cron/send_reminders.php`, `api/debug-db.php`, `api/seed-admin.php`, `updater.php`),
every HTML/JS file under `js/`/`assets/`/`css/` that renders API data, `.htaccess`, and the
whole `EtelTaxi/` tree for any unprotected sensitive path (e.g. checked-in `backups/`). No
deployment was performed — everything below is a code-level fix in the git working tree.
Deployment to `ethelmarket.com/EtelTaxi/` is a separate manual step handled by Dagi.

Every finding below was produced by reading the actual code (file/function/action names
cited throughout), not inferred. Every `.php` file edited was verified with `php -l` (no
syntax errors) and checked for **new** non-ASCII bytes introduced by the edit
(`python3 -c "print([ch for ch in open(f).read() if ord(ch)>127])"`, diffed against what
was already in the file — none found). EtelTaxi is not on the explicit hard-ASCII-constraint
list (only EtelID and EtelPay are), but ASCII discipline was applied anyway to every PHP
edit in this pass. JS edits (escaping fixes in `js/admin.js`/`js/driver.js`) only added
calls to the file's own existing `taxiEsc()` helper and introduced no new characters beyond
pre-existing emoji already used throughout the UI.

EtelTaxi is architecturally the most defensively-written of the Etel Group systems audited
so far (after Delivery & Ride, EtelGebeya, EtelPay, and Etel Madeya): a single dispatcher
(`api/boot.php`) with its own per-controller action whitelist, four thin controllers
(`AdminController.php`, `DriverController.php`, `OfficerController.php`,
`ServiceController.php`), separate DB tables per role (`taxi_admins`/`taxi_officers`/
`taxi_drivers` — no shared-table role-collapse risk the way Delivery's single `users` table
had), a `role_permissions`-driven capability system already matching the Etel Group
cross-company Employees-panel pattern, and no hardcoded secrets or guessable fallback
defaults anywhere in `config.php` (every secret's fallback is the empty string, which every
consuming function already treats as "not configured"). The one systemic gap found across
the codebase — never rejecting the `.env.example` template's own literal `CHANGE_ME`
placeholder as a *configured* secret — is the same class of bug found and fixed in EtelPay's
and Etel Madeya's audits this session, and is fixed centrally below.

---

## 1. Authorization enforcement

**Checked:** every `case` in `boot.php`'s driver/admin/officer/service action whitelist
(lines 244–276) against the corresponding controller's own auth call
(`require_driver_auth`/`require_admin_auth`/`require_officer_auth`/
`taxi_require_service_key`, all in `boot.php`/`ServiceController.php`), and every action
that accepts a driver/payment/association/officer ID for whether the authenticated caller
is actually authorized to act on that specific row (the IDOR class the task brief singled
out — "drivers should only see/act on their own association-dues/payment records; officers
should only see what their role permits"). Also checked `OfficerController.php`'s
independent auth (`require_officer_auth`, its own `taxi_officer_sessions` table, entirely
separate from `taxi_admin_sessions`) since it's reachable both via this app's own EtelID
sign-in and, per the task brief, indirectly trusted by EtelID's NFC bridge through
Delivery's `NfcController.php` — confirmed no cross-trust actually exists: EtelID's NFC
bridge calls into `ServiceController.php`'s `service_check_association_payment` (a
completely separate, service-key-gated bridge, not `OfficerController.php` at all), so
officer sessions and the NFC service bridge are two independent trust boundaries with no
shared code path.

### Fixed — Item 1: `admin_verify_payment`/`admin_reject_payment` let an association-scoped
admin verify or reject ANY other association's pending payment (Medium — cross-association
IDOR)

**Finding:** `admin_list_pending_payments` (`AdminController.php` line ~249) correctly
scopes non-super-admins to their own association
(`if ($admin['role'] !== 'super_admin') { $sql .= ' AND d.association_id = ?'; ... }`), and
`admin_update_association`/`admin_register_driver` both correctly check
`$admin['role'] !== 'super_admin' && intval($admin['association_id']) !== $assocId` before
allowing the write. But the two payment-status-changing actions did not carry the same
check: `admin_verify_payment` and `admin_reject_payment` (lines ~266–289, original) only
checked `require_admin_auth($pdo, 'payments.manage')` and then ran
`UPDATE taxi_dues_payments SET status = ... WHERE id = ? AND status = "pending_verification"`
with no `association_id` filter at all. Since `id` is a plain client-supplied integer, an
association-scoped admin (any non-`super_admin` with the `payments.manage` capability —
the default for a managed "Manager"/"Owner"/"Admin" staff role, per
`taxi_admin_capabilities()`) could verify or reject any other association's pending
payment just by guessing/incrementing the id — falsely marking another association's due as
paid, or rejecting a real payment that association never touched, entirely outside what
`admin_list_pending_payments` ever shows that admin.

**Fix applied** in `EtelTaxi/api/controllers/AdminController.php` (`admin_verify_payment`
and `admin_reject_payment`): before the `UPDATE`, non-super-admins now run an ownership
check joining `taxi_dues_payments` to `taxi_drivers` and comparing `d.association_id`
against the caller's own `association_id`, failing with 403 if it doesn't match — mirroring
the same pattern already used by `admin_update_association`:
```php
if ($admin['role'] !== 'super_admin') {
    $own = $pdo->prepare('SELECT p.id FROM ' . tbl('taxi_dues_payments') . ' p
        JOIN ' . tbl('taxi_drivers') . ' d ON d.id = p.driver_id
        WHERE p.id = ? AND d.association_id = ?');
    $own->execute(array($id, intval($admin['association_id'])));
    if (!$own->fetch()) fail('Not authorized for this payment.', 403);
}
```

### Verified clean

- **`require_driver_auth`** (`boot.php` line ~57): every driver action resolves the driver
  row from the session token first, then always scopes queries with `driver['id']` (e.g.
  `driver_get_status`, `driver_submit_payment`, `driver_get_payment_history` all use
  `WHERE driver_id = ?` with the authenticated driver's own id) — a driver can never read or
  act on another driver's row. `driver_complete_etelid_registration` independently
  authenticates via a fresh EtelID session-token lookup (documented as intentional, since
  the driver has no `taxi_drivers` row yet for `require_driver_auth` to find) and only ever
  creates a new row tied to that verified `etel_id` — never updates an existing one.
- **`require_admin_auth($pdo, $capability)`** (`boot.php` line ~106): every admin action
  passes a specific capability string (`drivers.view`, `payments.manage`, `owner.only`,
  etc.), checked via `taxi_admin_has_capability()` against the admin's resolved capability
  list — `'*'` (unrestricted) only for non-EtelGroup-managed accounts or managed accounts
  with staff_role Owner/Admin/Manager, a safe read-only baseline
  (`drivers.view`,`associations.view`) for any other/unrecognized managed role name, and
  zero capabilities once a managed admin's EtelGroup role is removed — matches the
  documented Etel Group capability-system pattern exactly, confirmed by reading
  `taxi_admin_capabilities()` line-by-line.
- **`admin_create_association`/`admin_register_officer`**: both correctly gate on
  `$admin['role'] !== 'super_admin'` in addition to the capability check — association-wide
  and city-wide-officer actions are super-admin-only, not just capability-gated.
  `admin_update_association`/`admin_register_driver` correctly check the target
  `association_id` against the caller's own, as noted above.
- **`OfficerController.php`**: `officer_verify_by_plate` and `officer_logout` both require
  `require_officer_auth($pdo)` (its own `taxi_officer_sessions`/`taxi_officers` tables,
  independent of admin/driver sessions). `officer_etelid_login` never creates a new officer
  account by matching phone/etel_id alone — it also requires EtelID's live "Traffic Officer"
  role assignment via `taxi_egid_check_role()`, and explicitly filters out any other role
  name (`Manager`, `Dispatcher`, ...), which belongs to the admin dashboard instead. An
  officer session grants read access only to `officer_verify_by_plate`'s driver-status
  lookup — no write actions exist for officers at all.
- **`ServiceController.php`'s `service_check_association_payment`**: gated by
  `taxi_require_service_key()` (`hash_equals()` against `ETELTAXI_SERVICE_KEY`, a secret
  entirely separate from `ETELID_SERVICE_KEY` and from officer/admin/driver sessions) —
  this is the one path EtelID's NFC bridge actually reaches, and it is read-only (driver
  compliance status + last verified payment, no wallet/PIN/session data).
- **`public_verify_permit`**: deliberately unauthenticated, but bounded by design — takes
  only a signed, date-stamped `verify_code` (`driverId.Ymd.hmac`, `hash_equals()`-checked
  against `TAXI_QR_SIGNING_KEY`, expires same-day), not a raw plate or driver id, so there is
  no globally-searchable "look up anyone's permit" endpoint — confirmed this is the
  documented intent in both `DriverController.php`'s and `verify.js`'s comments, not an
  oversight.
- **`driver_get_bank_accounts`**: intentionally public (no `require_driver_auth`) — only
  returns already-public bank routing info (`bank_key`, `display_name`, `account_name`,
  `account_number` for `active = 1` accounts), the same information every driver needs to
  see before signing in to pay their due. Not a leak of anything sensitive.
- **`admin_export_drivers_csv`**: correctly scopes to the caller's own association for
  non-super-admins (`$assocFilter = $admin['role'] === 'super_admin' ? null : ...`), same
  as `admin_get_dashboard_stats`/`admin_get_revenue_trend`/`admin_list_overdue`.

### Needs Dagi's judgment call

- **`admin_login` (phone+PIN) has no rate limiting or lockout** — an attacker who knows or
  guesses an admin's phone number can attempt PINs indefinitely (bcrypt via
  `password_verify()` slows brute force somewhat, but there's no explicit throttle). Same
  gap exists for `driver_login`. This matches the pattern already accepted as a judgment
  call in the Delivery & Ride and EtelGebeya audits (no rate-limiting infrastructure exists
  anywhere in this codebase yet, and adding one is a cross-cutting infra decision, not a
  one-file fix) — flagging for Dagi rather than auto-fixing.
- **`officer_verify_by_plate` loads every driver row and iterates in PHP** (`SELECT * FROM
  taxi_drivers` with no `WHERE`, then a PHP loop normalizing each plate) rather than
  filtering in SQL. Not a security bug (results are still correctly scoped to the matching
  plate before anything is returned), but worth flagging as a scaling concern once the
  drivers table grows — Dagi's call whether to convert to a SQL `WHERE` with the normalized
  plate precomputed in a column, given real driver-count growth.

---

## 2. SQL injection review

**Checked:** every `$pdo->prepare()`/`$pdo->query()` call across all four controllers, all
four libs, `boot.php`, `migrate.php`, `seed-admin.php`, `debug-db.php`, and
`cron/send_reminders.php` — specifically for any place a request-controlled value is
concatenated directly into SQL rather than bound as a parameter.

### Verified clean

- **Every single query in `AdminController.php`, `DriverController.php`,
  `OfficerController.php`, and `ServiceController.php` uses `$pdo->prepare()` with bound
  `?` placeholders** for every value that originates from `$body`/`$_GET`/a session lookup —
  confirmed by reading all four files in full, no exceptions found.
- **Table/column names built from constants, never request input**: `tbl($t)` (`DB_PREFIX .
  $t`) is always called with a hardcoded literal table name argument (e.g. `tbl('taxi_drivers')`),
  never a variable derived from user input, across every controller.
- **`admin_update_association`/`admin_update_bank_account`/`admin_update_role_permissions`**
  build their `SET` clause dynamically (`$fields[] = "$f = ?"` for `$f` in a fixed, hardcoded
  whitelist array like `array('name_am','name_en','route_am','route_en')`), never from a
  request-supplied column name — the column names themselves are compile-time constants, only
  the bound values are request-controlled, and those are always parameterized.
- **`admin_get_dashboard_stats`/`admin_get_revenue_trend`/`admin_export_drivers_csv`**
  concatenate `intval($assocFilter)` directly into the SQL string rather than binding it
  (e.g. `' AND d.association_id = ' . intval($assocFilter)`) — not parameterized, but safe
  because `intval()` on a value that already came from `$admin['association_id']` (a DB
  column, an integer) or a hardcoded `null` check guarantees a pure integer literal, never
  attacker-controlled string content. No injection vector.
- **`migrate.php`**: SQL statements are read from `.sql` files on disk under
  `api/migrations/`, not from any request input, and the whole script is gated by
  `TAXI_ADMIN_KEY` (`hash_equals()`) before it runs anything.
- **`officer_verify_by_plate`'s plate matching**: normalization/matching happens entirely in
  PHP (`strtoupper(preg_replace(...))`) against rows already fetched with a plain
  `SELECT * FROM taxi_drivers` (no `WHERE` built from the plate at all) — the plate string
  never reaches SQL.
- **`lib/taxi_payments.php`**: all three functions (`taxi_period_window`,
  `taxi_current_period`, `taxi_compute_driver_status`) take only already-validated integer
  ids and DB-sourced association settings; every query is parameterized.

---

## 3. XSS / output escaping

**Checked:** every `innerHTML`/`insertAdjacentHTML`/`document.write` sink across
`js/core.js`, `js/admin.js`, `js/admin-roles.js`, `js/driver.js`, `js/verify.js`, and
`update-panel.html`, for whether request/API-sourced data reaching that sink is passed
through the codebase's own `taxiEsc()` helper (`core.js`, HTML-entity-escapes `&<>"'`)
before being interpolated into an HTML template string, versus rendered raw.

### Fixed — Item 1: `loadAssociations()`, `loadDriverFormAssociations()`, and
`loadAssociationsInto()` render association `name_am`/`name_en`/`route_am` unescaped
(Low/Medium — stored XSS via admin-controlled association fields)

**Finding:** `admin_create_association`/`admin_update_association` accept `name_am`,
`name_en`, `route_am`, `route_en` from the request body with only `trim()` applied
server-side — no HTML stripping, by design (these are legitimate free-text fields, e.g.
Amharic association names). Three client-side render paths then interpolated these values
directly into `innerHTML`/an `<option>` list without `taxiEsc()`:
- `js/admin.js`'s `loadAssociations()` (Associations panel table: `${a.name_am}`,
  `${a.name_en}`, `${a.route_am || '-'}`)
- `js/admin.js`'s `loadDriverFormAssociations()` (the "New Driver" form's association
  `<select>`: `${a.name_en || a.name_am}`)
- `js/driver.js`'s `loadAssociationsInto()` (the driver registration form's association
  `<select>`: `${a.name_am}`, `${a.route_am}`)

Since any admin with `associations.manage` (not necessarily a super-admin — a managed
"Manager"/"Owner"/"Admin" staff role gets `'*'` by default per `taxi_admin_capabilities()`)
can set these fields, a compromised or malicious lower-trust admin account could store an
HTML/script payload in an association name that then runs in the browser of every other
admin who opens the Associations panel or the New Driver form, and every driver who opens
the registration screen — a genuine stored-XSS path, even though the immediate attacker
already needs some admin credential to plant it.

**Fix applied**: wrapped all five interpolations above in `taxiEsc()` in
`EtelTaxi/js/admin.js` (`loadAssociations`, `loadDriverFormAssociations`) and
`EtelTaxi/js/driver.js` (`loadAssociationsInto`), matching the escaping already correctly
applied to `full_name`/`vehicle_plate`/`phone`/`reference_number` elsewhere in both files.

### Fixed — Item 2: `loadBank()` renders bank `display_name`/`account_name`/
`account_number` unescaped, including inside an HTML attribute (Medium — stored XSS +
attribute-breakout)

**Finding:** `js/admin.js`'s `loadBank()` rendered `a.display_name` as raw `innerHTML` text
content (`<strong>${a.display_name}</strong>`) and `a.account_name`/`a.account_number`
directly inside `value="..."` attributes (`value="${a.account_name || ''}"`). The latter is
worse than a plain text-content injection: a value containing a `"` character breaks out of
the `value` attribute entirely, letting an injected payload add arbitrary attributes (e.g.
`onfocus=...`) to the `<input>` itself. These fields are set via
`admin_update_bank_account`, gated by the `finance.manage` capability — a real but
lower-privilege capability than full admin, so a staff account scoped only to managing bank
accounts could plant a payload that executes in a higher-privileged admin's browser the next
time they open the Bank Accounts panel (a capability-escalation path via stored XSS).

**Fix applied**: wrapped `display_name`, `account_name`, and `account_number` in `taxiEsc()`
in `EtelTaxi/js/admin.js`'s `loadBank()`, for both the text-content and the attribute-value
positions.

### Verified clean

- **`js/core.js`'s `taxiEsc()`** itself: a straightforward, correct HTML-entity escape of
  `& < > " '` — no bypass found.
- **`js/verify.js`** (the public verification page — the one screen a completely
  unauthenticated visitor's browser renders API-sourced content on): every field in
  `renderResult()`/`renderFail()` (`full_name`, `vehicle_plate`, association name/route,
  `valid_through`, error messages) is passed through `taxiEsc()`. This is the highest-risk
  surface for XSS in the app (public, no login) and it's fully covered.
- **`js/driver.js`**: `loadStatus()`, `loadHistory()`, `taxiPrintReceipt()`'s row builder
  (via `core.js`) — every driver/payment-history field (`period_month`, `payment_method`
  label, `reference_number`) that renders via `innerHTML` is wrapped in `taxiEsc()`, matching
  the file's own header comment: *"Driver-submitted text ... gets rendered via innerHTML in
  admin/verify tables - escape it first."* The two association-select gaps were the only
  exceptions, fixed above.
- **`js/admin.js`**: `loadDrivers()`, `loadPending()`, `loadOverdue()`, `loadOfficers()` all
  correctly wrap every driver/officer/payment field (`full_name`, `phone`, `vehicle_plate`,
  `association_name`, `payment_method`, `reference_number`) in `taxiEsc()`. `loadAssociations`
  and `loadBank` were the two exceptions, fixed above.
- **`js/admin-roles.js`**: `loadRolesPanel()`/`editRolePermissions()` correctly wrap
  `role_name` and capability labels in `taxiEsc()`, including the single-quote-escaped
  `onclick` argument for `deleteRolePermissions` (`taxiEsc(r.role_name).replace(/'/g,
  "\\'")`) — role names are admin-entered free text (`admin_update_role_permissions`), and
  this path correctly guards against both HTML injection and the quote-breakout that would
  otherwise be possible in an inline `onclick="...('...')"` attribute.
- **`update-panel.html`**: the one raw (non-`taxiEsc()`) interpolation, `Object.entries(res.
  data.files).map(([name, f]) => ...)`, renders only `updater.php`'s own hardcoded status
  filenames (`'driver.html'`, `'api/boot.php'`, etc. — a fixed array literal in
  `updater.php`, never request-controlled) plus numeric size/timestamp fields — not an
  injection vector.
- **`core.js`'s `taxiPrintReceipt()`**: every row value (`driverName`, `plate`, `association`,
  `period`, `method`, `reference`, `status`) is passed through `taxiEsc()` before being
  written into the popup window's `document.write()` call — the one `document.write()` sink
  in the codebase, and it's fully escaped.

---

## 4. Session / cookie security

**Checked:** how driver/admin/officer sessions are created, stored, and transmitted, across
`boot.php`'s three `require_*_auth()` functions and all three login actions.

### Verified clean / not applicable

- **No cookies are used anywhere in this codebase.** Every session is a 32-byte random hex
  token (`bin2hex(random_bytes(32))`) issued at login, stored client-side in
  `localStorage` (`taxiSetToken()`/`taxiGetToken()` in `core.js`, keyed `taxi_<kind>_token`),
  and sent back as a Bearer `Authorization` header (`taxiApi()` in `core.js`) — there is no
  `Set-Cookie` header, no `document.cookie` reference anywhere in the tree (confirmed via
  grep), and therefore no CSRF surface in the traditional cookie-session sense: a
  cross-origin page cannot make an authenticated request on a signed-in user's behalf,
  because it cannot read that user's `localStorage` token to attach the header. The
  practical consequence is that the earlier XSS findings (Section 3) are the real token-theft
  risk in this architecture — an XSS payload running in an admin's session *can* read
  `localStorage` and exfiltrate the token — which is exactly why Section 3's fixes matter
  here as much as they do for cookie-based CSRF in a different architecture.
- **Session expiry is enforced server-side, not just client-side**: every session lookup
  query includes `AND expires_at > NOW()` (driver: 90 days, admin: 30 days, officer: 30
  days) — an expired token is rejected even if still present in `localStorage`.
- **Logout is server-side, not just a local token clear**: `officer_logout` explicitly
  `DELETE`s the session row by token before the client clears `localStorage` — confirmed the
  same pattern is intended for admin/driver (no explicit admin/driver logout action exists
  server-side yet, only client-side `taxiClearToken()` — see judgment call below).
- **CSV export token-in-URL** (`admin_export_drivers_csv`, `?token=...`): documented as
  intentional in `require_admin_auth()`'s own comment — a plain `<a href>` download link
  can't set a custom header, so the token is accepted as a query param for this one action
  only. This does put the token in server access logs and browser history, a real but
  minor exposure; acceptable given the alternative (no CSV export at all, or a
  significantly more complex download-token-exchange flow) is a judgment call, not a bug.

### Needs Dagi's judgment call

- **No server-side logout endpoint exists for admin or driver sessions** — only
  `officer_logout` deletes its session row server-side; `adminLogout()`/`doLogout()` in
  `js/admin.js`/`js/driver.js` only call `taxiClearToken()` (removes the `localStorage`
  entry) without ever telling the server to invalidate that token. A stolen admin/driver
  token (e.g. via an XSS payload before Section 3's fixes were applied, or a synced/shared
  device) remains valid for its full 30/90-day expiry even after the legitimate user "logs
  out." Adding `admin_logout`/`driver_logout` actions mirroring `officer_logout` is a small,
  clearly-scoped fix, but touches login/session flow broadly enough that it's flagged for
  Dagi to decide on rather than silently added.

---

## 5. Error handling and logging sweep

**Checked:** every `catch` block and every place a raw exception message or internal detail
could reach an HTTP response, across all controllers, libs, and the standalone maintenance
scripts (`migrate.php`, `seed-admin.php`, `debug-db.php`, `cron/send_reminders.php`,
`updater.php`).

### Verified clean

- **`boot.php`'s `fail_ex($e, $publicMsg, $code)`** (used by `db()`'s connection-failure
  path): logs the real exception via `error_log()` and returns only the generic
  `$publicMsg` to the client — never the raw `$e->getMessage()`. Confirmed this is the only
  path through which a DB-level PDO exception could otherwise leak connection detail, and
  it's correctly gated.
- **`driver_complete_etelid_registration`'s cross-DB lookup failure** (line ~93): logs the
  real error via `error_log()` and returns a generic, specific-but-not-leaky message
  ("cross-app database access may not be configured on this server yet") — matches the
  pattern flagged as correct in the Delivery & Ride audit's own `NfcController.php` review.
- **`require_driver_auth`'s EtelID SSO fallback** (line ~97): a cross-DB lookup exception is
  caught and silently degrades to "not signed in" (`fail('Session expired or invalid...')`)
  rather than a 500 — documented in its own comment as a deliberate fallback, not an error
  state, and it never surfaces the caught exception's message to the client.
- **`migrate.php`**: does print the real `$e->getMessage()` to its own output (`echo "
  FAILED: " . $e->getMessage()`), but this is a `text/plain` admin-only maintenance tool
  gated by `TAXI_ADMIN_KEY` (`hash_equals()`) — an authenticated admin operator seeing the
  real SQL error is the intended behavior (the file's own comments explain this is needed to
  actually debug a failed migration), not a leak to an unauthenticated party.
- **`seed-admin.php`**: echoes `$e->getMessage()` on a DB connection failure, same reasoning
  as `migrate.php` — gated by `TAXI_ADMIN_KEY` first.
- **`debug-db.php`**: by design (and by its own header comment, which documents this
  explicitly) echoes DB host/name/user/prefix, connection success/failure, and — on the
  cross-DB failure branch — real MySQL error text and EtelID's real table/column names. This
  is the single most sensitive of the three maintenance scripts, but it is gated by the same
  `TAXI_ADMIN_KEY` `hash_equals()` check as `migrate.php`/`seed-admin.php` (confirmed present
  and correct, `debug-db.php` lines 18–23) — never returns the DB password value itself
  (only its length), and its header comment already documents it as a temporary diagnostic
  meant to be deleted after use. See Section 7 for the judgment call on whether it should be
  deleted now regardless of being correctly gated.
- **`cron/send_reminders.php`**: in CLI mode (`$isCli`), DB connection failures go to
  `STDERR`, not any HTTP response — correct for a cron job. In its HTTP-fallback mode, it's
  gated by the same `TAXI_ADMIN_KEY` check before anything else runs.
- **`updater.php`**: `err()` always returns a fixed, generic message per call site (e.g.
  `'Invalid or corrupt zip file'`, `'Cannot create directory: $dir'` — the latter does
  include a server path, but only to an already-authenticated caller who supplied that exact
  `path` value themselves, not a leak of anything they didn't already know).
- **No password/PIN/service-key comparison anywhere echoes back partial secret material** —
  every failure path returns a fixed generic message (`'Incorrect phone number or PIN.'`,
  `'Invalid or missing service key.'`, `'Session expired or invalid.'`) with no length,
  prefix, or hash fragment of the actual secret ever included, across all four auth
  functions and all three login actions. This matches the specific concern raised by the
  EtelPay audit's `etelid_members_list.php` finding (a debug-fingerprint leak of
  `ETELID_SERVICE_KEY`'s length + first/last 6 characters) — confirmed no equivalent exists
  anywhere in EtelTaxi.

---

## 6. Input validation audit

**Checked:** every action's handling of required fields, type coercion
(`intval`/`floatval`/`trim`), and enum-style values (`payment_method`, `role`, `status`)
against what the DB schema (`api/migrations/001_schema.sql` and later migrations) actually
allows.

### Verified clean

- **`payment_method` is validated against a fixed allow-list** (`driver_submit_payment`:
  `in_array($method, array('etelpay_wallet','telebirr','cbe','abysinia','cash'), true)`)
  before being used anywhere, matching the DB's own `ENUM('etelpay_wallet','telebirr','cbe',
  'abysinia','cash')` on `taxi_dues_payments.payment_method` — no way to insert an
  out-of-enum value.
- **PIN length is enforced both client- and server-side** (`driver_register`: `if
  (strlen($pin) < 4) fail(...)`), and stored only as a `password_hash()` output, never
  plaintext.
- **All numeric IDs are coerced via `intval()` before use in a query or an ownership
  comparison** — `association_id`, driver/payment/officer/role ids, `grace_period_days` —
  confirmed across all four controllers, closing off any type-juggling surprise from a
  string ID.
- **`admin_register_driver`/`driver_register`/`driver_complete_etelid_registration`** all
  require `license_number`, `license_full_name`, `license_dob` together — no path exists to
  register a driver with a partial license record, and the identity cross-check against
  EtelID (`taxi_check_license_identity`/inline DOB compare) runs on every registration path,
  flagging (not blocking) a mismatch for later association review.
- **`officer_verify_by_plate`'s plate normalization** (`strtoupper(preg_replace('/\s+/', '',
  $plateRaw))`) is applied identically to both the stored and the input plate before
  comparison, and an empty `$plateRaw` is rejected before the query runs.
- **`admin_update_role_permissions`'s `capabilities` array** is coerced to a de-duplicated
  array of strings (`array_values(array_unique(array_map('strval', $caps)))`) before being
  JSON-encoded and stored — no way to smuggle a non-string/nested value into the stored
  capability list that `taxi_admin_has_capability()`'s `in_array($capability, $caps, true)`
  strict check would later misinterpret.

### Needs Dagi's judgment call

- **No explicit phone-number format validation** anywhere (`driver_register`,
  `admin_register_driver`, `admin_register_officer` all just `trim()` the phone value) —
  any non-empty string is accepted as a phone number. This is consistent with the rest of
  Etel Group's codebases (Delivery/EtelGebeya have the same looseness, already noted as
  intentional there to tolerate real-world formatting variance across `0912...`/`+251912...`/
  `251912...`), and `service_check_association_payment`'s `RIGHT(phone, 9)` matching already
  handles the resulting format variance gracefully — not treating this as a bug, but noting
  it for consistency with the other systems' judgment calls.
- **`vehicle_plate` has no format/pattern validation** — any string is accepted as a plate.
  Given Ethiopian plate formats vary by region/vehicle-class and the app already normalizes
  case/whitespace for matching purposes (`officer_verify_by_plate`,
  `public_verify_permit`'s driver lookup), a strict format check risks rejecting legitimate
  plates more than it prevents anything — Dagi's call on whether association staff should be
  trusted to enter this correctly (current behavior) or whether a format hint/soft-warning
  is worth adding.

---

## 7. Known open items

- **`api/debug-db.php` is a diagnostic tool that should still be deleted from the live
  server once no longer needed**, per its own header comment. It is correctly gated by
  `TAXI_ADMIN_KEY` (verified in Section 1/5 above) — not an unauthenticated leak — but it
  still exposes real DB host/name/user/prefix and, on the cross-DB failure branch, EtelID's
  real internal table/column names to anyone who has (or guesses/leaks) `TAXI_ADMIN_KEY`.
  Since `TAXI_ADMIN_KEY` is also the same key that gates `migrate.php` and `seed-admin.php`
  (account-creation), a leaked key already grants more than this file adds — but deleting
  `debug-db.php` once cross-DB connectivity is confirmed working (its whole stated purpose)
  removes one more thing that key can reach. Left in place for now since it may still be in
  active use for the EtelID cross-DB grant debugging its own comments describe; Dagi's call
  on when it's safe to delete.
- **`api/seed-admin.php` should be deleted (or its `TAXI_ADMIN_KEY` rotated) after the first
  super-admin account is created**, per its own header comment — same reasoning as EtelPay's
  and Delivery's equivalent one-time bootstrap scripts. Currently correctly gated
  (`hash_equals()` against `TAXI_ADMIN_KEY`, confirmed in Section 1), so not an open
  vulnerability, just standing operational cleanup.
- **EtelPay wallet payment (`etelpay_wallet`) is an honest stub**, not a faked success —
  `driver_submit_payment` explicitly rejects it with a 503 and a clear message
  (`DriverController.php` line ~251) rather than pretending to process a payment that never
  happened. Documented here as intentional, matching the code's own comment ("no EtelPay
  codebase to integrate against yet ... intentionally NOT faked as a success").
- **No automated test suite exists** for any of the fixes in this document — verification
  was `php -l` (syntax) plus manual code tracing of every changed code path. Matches every
  other Etel Group system audited so far; none have automated tests.

---

## 8. Full API endpoint inventory

All actions are dispatched through `POST/GET api/boot.php?action=<name>`, JSON body (or
`$_POST` fallback) for parameters, JSON response `{success, data|message, v}`.

### Driver actions (`DriverController.php`)

| Action | Auth | Notes |
|---|---|---|
| `driver_list_associations` | None (public) | Lists active associations for registration |
| `driver_etelid_login` | EtelID identifier+password | Returns driver session token, or `needs_registration` |
| `driver_complete_etelid_registration` | EtelID session token (Bearer) | Creates the `taxi_drivers` row for an already-verified EtelID member |
| `driver_register` | None (creates account) | Phone+PIN self-registration |
| `driver_login` | Phone+PIN | Returns driver session token |
| `driver_get_status` | Driver session | Own permit status + daily signed QR verify code |
| `driver_submit_payment` | Driver session | Submits/resubmits a dues payment for verification |
| `driver_get_payment_history` | Driver session | Own last 24 payment records |
| `get_vapid_public_key` | None (public) | Web Push public key, not a secret |
| `driver_register_push_token` | Driver session | Stores a Web Push subscription |
| `driver_get_bank_accounts` | None (public, intentional) | Active bank accounts for payment instructions |
| `public_verify_permit` | Signed verify_code (no login) | QR-scan permit check, code expires same-day |

### Admin actions (`AdminController.php`)

| Action | Auth | Capability | Notes |
|---|---|---|---|
| `admin_etelid_login` | EtelID identifier+password | — | Only matches an EXISTING admin phone/etel_id, or auto-creates if EtelGroup grants a non-officer role |
| `admin_login` | Phone+PIN | — | |
| `admin_list_associations` | Admin session | `associations.view` | |
| `admin_create_association` | Admin session | `associations.manage` + super_admin | |
| `admin_update_association` | Admin session | `associations.manage` + own-association check | |
| `admin_list_drivers` | Admin session | `drivers.view` | Scoped to own association unless super_admin |
| `admin_register_driver` | Admin session | `drivers.manage` + own-association check | Runs EtelID identity cross-check |
| `admin_list_pending_payments` | Admin session | `payments.view` | Scoped to own association unless super_admin |
| `admin_verify_payment` | Admin session | `payments.manage` + own-association check *(fixed this pass)* | |
| `admin_reject_payment` | Admin session | `payments.manage` + own-association check *(fixed this pass)* | |
| `admin_get_bank_accounts` | Admin session | `finance.view` | |
| `admin_update_bank_account` | Admin session | `finance.manage` | |
| `admin_get_dashboard_stats` | Admin session | `associations.view` | Scoped to own association unless super_admin |
| `admin_list_overdue` | Admin session | `payments.view` | Scoped to own association unless super_admin |
| `admin_register_officer` | Admin session | `drivers.manage` + super_admin | City-wide, not association-scoped |
| `admin_list_officers` | Admin session | `drivers.view` | |
| `admin_get_revenue_trend` | Admin session | `finance.view` | Scoped to own association unless super_admin |
| `admin_export_drivers_csv` | Admin session (token via query param) | `drivers.view` | Scoped to own association unless super_admin |
| `admin_list_role_permissions` | Admin session | `owner.only` | |
| `admin_update_role_permissions` | Admin session | `owner.only` | |
| `admin_delete_role_permissions` | Admin session | `owner.only` | |
| `admin_get_my_capabilities` | Admin session | — | |

### Officer actions (`OfficerController.php`)

| Action | Auth | Notes |
|---|---|---|
| `officer_etelid_login` | EtelID identifier+password | Only matches an EXISTING officer, or auto-creates if EtelGroup grants exactly the "Traffic Officer" role |
| `officer_verify_by_plate` | Officer session | No auth-free plate-search endpoint exists |
| `officer_logout` | Officer session | Server-side session delete |

### Service-to-service (`ServiceController.php`)

| Action | Auth | Notes |
|---|---|---|
| `service_check_association_payment` | `X-Etel-Service-Key` header, `hash_equals()` against `ETELTAXI_SERVICE_KEY` | Called by Delivery's NFC bridge; read-only |

### Standalone maintenance scripts (not routed through `boot.php`)

| File | Auth | Notes |
|---|---|---|
| `api/migrate.php` | `?key=TAXI_ADMIN_KEY`, `hash_equals()` | Runs pending `api/migrations/*.sql` |
| `api/seed-admin.php` | `?key=TAXI_ADMIN_KEY`, `hash_equals()` | One-time first-super-admin bootstrap |
| `api/debug-db.php` | `?key=TAXI_ADMIN_KEY`, `hash_equals()` | DB connectivity diagnostic |
| `api/cron/send_reminders.php` | CLI: none needed; HTTP: `?key=TAXI_ADMIN_KEY`, `hash_equals()` | Daily push reminders, at-most-once per driver/period/type |
| `updater.php` | `X-Auth` header or `auth` param, `hash_equals()` against `UPDATER_PASSWORD` *(fixed this pass)* | File-deploy tool (`status`/`upload`/`upload_zip`/`delete_backup`) |

---

## 9. Sample request/response pairs

**`driver_login`** — success:
```
POST api/boot.php?action=driver_login
{"phone":"0912345678","pin":"1234"}

200 {"success":true,"data":{"token":"<64-hex>","driver":{"id":7,"full_name":"...","vehicle_plate":"..."}},"v":"1.0.0"}
```
Failure (wrong PIN — generic message, no hint which part was wrong):
```
401 {"success":false,"message":"Incorrect phone number or PIN.","v":"1.0.0"}
```

**`admin_verify_payment`** — cross-association attempt, after this pass's fix:
```
POST api/boot.php?action=admin_verify_payment
Authorization: Bearer <association-scoped admin token>
{"id": 91}   // payment belongs to a DIFFERENT association

403 {"success":false,"message":"Not authorized for this payment.","v":"1.0.0"}
```

**`service_check_association_payment`** — missing/wrong service key:
```
POST api/boot.php?action=service_check_association_payment
X-Etel-Service-Key: wrong-key
{"etel_id":"ETL-000123"}

401 {"success":false,"message":"Invalid or missing service key.","v":"1.0.0"}
```

**`public_verify_permit`** — tampered code:
```
POST api/boot.php?action=public_verify_permit
{"verify_code":"7.20260921.deadbeefdeadbeefdead"}

400 {"success":false,"message":"Invalid or tampered verification code.","v":"1.0.0"}
```

**`updater.php`** — status check, after this pass's `hash_equals()` fix:
```
POST updater.php?action=status
auth=<correct password>

200 {"ok":true,"message":"System status loaded","data":{"files":{...},"php_version":"8.1.34","disk_free":"... MB","backups":[...]}}
```

---

## Summary of fixes applied this pass

| File | Fix |
|---|---|
| `EtelTaxi/api/controllers/AdminController.php` | Added association-ownership check to `admin_verify_payment`/`admin_reject_payment` (cross-association IDOR) |
| `EtelTaxi/api/config.php` | `etel_taxi_env()` now rejects the `.env.example` template's literal `CHANGE_ME` placeholder (case-insensitive) as a configured value, closing the class of bug found in EtelPay's `EP_SECRET`/updater password and Etel Madeya's `etelid_bridge.php` |
| `EtelTaxi/updater.php` | Rejects the same `CHANGE_ME` placeholder for `ETEL_TAXI_UPDATER_PASS`; switched the auth comparison from `!==` to `hash_equals()` for timing-safety, matching every other secret comparison in the codebase |
| `EtelTaxi/js/admin.js` | Escaped `name_am`/`name_en`/`route_am` in `loadAssociations()`/`loadDriverFormAssociations()`, and `display_name`/`account_name`/`account_number` (including in `value="..."` attribute position) in `loadBank()` — stored XSS |
| `EtelTaxi/js/driver.js` | Escaped `name_am`/`route_am` in `loadAssociationsInto()` — stored XSS |

All edits verified with `php -l` (PHP files) and checked for new non-ASCII bytes (none
introduced). No files were deployed; all changes are in the git working tree only.
