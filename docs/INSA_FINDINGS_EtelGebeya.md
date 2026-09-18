# INSA Pre-Submission Security Findings -- Etel Gebeya

Prepared to fill the `[TO COMPLETE]` placeholders in an
`INSA_Audit_Package_EtelGebeya.docx` (Sections 5.1-5.4, 6, 7, 8.3), mirroring the
format and rigor of `docs/INSA_FINDINGS.md` (Etel Delivery & Ride). Scope:
`EtelGebeya/` (the Etel Gebeya PHP/MySQL marketplace -- a routed MVC app under
`app/Controllers`/`app/Models`/`app/Core`, plus a legacy flat action-dispatch admin
API at `api/admin.php` used by the static `admin.html`/`admin-panel.js` admin
panel), living in this repo at `https://github.com/Etel-FTBG/Etel-FTBG`. No
`INSA_Audit_Package_EtelGebeya.docx` exists in the repo yet -- this file stands on
its own and can be dropped straight into that template's placeholders once it
exists. No deployment was performed -- everything below is a code-level fix in
the git working tree. Deployment to `ethelmarket.com/EtelGebeya/` happens
separately, manually, by Dagi.

Every finding below was produced by reading the actual code (file/function names
cited throughout), not inferred. Every `.php` file edited was verified with
`php -l` (no syntax errors). This codebase already carries pre-existing non-ASCII
characters (Amharic "ኢE Pay" wallet-brand text, box-drawing `─` comment
separators) and tolerates them the same way Delivery's codebase does -- every
edit below was checked to introduce **no new** non-ASCII bytes
(`python3 -c "print([ch for ch in open(f).read() if ord(ch)>127])"` before/after
diffed) even though the file as a whole isn't purely ASCII.

---

## 1. Authorization enforcement (Section 5.1)

**Checked:** every action in `AdminController.php`, `AuthController.php`,
`CheckoutController.php`, `CustomerController.php`, `StorefrontController.php`,
`VendorController.php`, `GeocodeController.php`, `InstallController.php`,
`CartController.php` -- for whether the caller's role/ownership is verified
server-side before reading or writing data -- plus every `case` in the legacy
`api/admin.php` action switch and its `auth('capability')` gate.

Two controllers referenced by `public/index.php`'s router
(`\App\Controllers\PaymentController`, `\App\Controllers\CourierController`,
routes under `/payments/*` and `/courier/*`) **do not exist as files** in
`app/Controllers/`. Hitting those routes throws a class-not-found `\Throwable`,
caught by `index.php`'s top-level handler (shows a generic 500 page, or the
real stack trace only when `app.debug` is on -- see Section 5). Not an
authorization gap in itself (nothing is exposed), but flagged since it means
webhook payment callbacks and the courier flow are currently dead code paths,
not a "these are gated and safe" situation -- worth Dagi's attention if either
is expected to be live.

### Fixed

**`InstallController::run()` (POST `/install`) had no "already installed" guard
at all -- Critical, full reinstall / site-takeover path.**

`InstallController::index()` (GET `/install`) correctly refuses to show the
install form once `isInstalled()` is true. `run()` (the POST handler that
actually creates the database, runs `schema.sql`/`seed.sql`, sets the admin
password, and rewrites `config/app.php` with new DB credentials) never checked
`isInstalled()` at all -- it went straight from `Request::isPost()` into
connecting to whatever MySQL host/credentials the POST body supplied, running
`CREATE DATABASE IF NOT EXISTS`, executing the full schema/seed SQL against it,
overwriting `config/app.php`'s DB block, and resetting the admin account's
password and email to attacker-supplied values, **even on a live, already-
installed production site.** Anyone who could reach `/install` with a POST
body -- no authentication of any kind is required to reach this route --
could point the app at a database they control (or blow away the existing
one) and mint themselves a fresh admin account.

Made worse by a second bug: **`isInstalled()`'s own path was wrong and always
returned `false`,** so even the GET form's existing guard never actually
engaged. `isInstalled()` computed `__DIR__ . '/../../../storage/.installed'`
from `app/Controllers/InstallController.php` -- three `..` segments walks one
directory *above* the whole `EtelGebeya/` project root (`app/Controllers` ->
`app` -> `EtelGebeya` -> parent of `EtelGebeya`), where `storage/.installed`
can never exist. `is_file()` on a nonexistent path is always `false`, so this
check silently never fired on either the GET or (once added) the POST path.
The same off-by-one existed in `writeConfig()`'s `$path` (three `..` instead
of two -- meant a fresh install's DB credentials were never actually
persisted back to `config/app.php`, a real functional bug alongside the
security one) and in `runChecks()`'s `$root` (`dirname(dirname(dirname(__DIR__)))`,
also one level too many -- meant "config writable"/"storage writable" always
reported false on the install pre-flight screen). The one place that already
computed the path correctly was the "mark installed" line itself
(`file_put_contents(__DIR__ . '/../../storage/.installed', ...)`, two `..`),
which is what confirmed the right depth to fix the other three to.

**Fix applied**, file `EtelGebeya/app/Controllers/InstallController.php`:
- Added the same `isInstalled()` check to the top of `run()` that `index()`
  already has, refusing the POST outright once the site is installed.
- Fixed `isInstalled()`'s path from `/../../../storage/.installed` to
  `/../../storage/.installed`.
- Fixed `writeConfig()`'s path the same way (`/../../../config/app.php` ->
  `/../../config/app.php`).
- Fixed `runChecks()`'s `$root` from `dirname(dirname(dirname(__DIR__)))` to
  `dirname(__DIR__, 2)`.

Verified with `php -l` and the ASCII check (no non-ASCII introduced).

### Verified clean

- **`AdminController.php`**: every single action runs through the
  constructor's `guard()` call (`require login -> redirect('/login')`, then
  `require isAdmin() -> redirect('/')`) before any handler body executes --
  there is no per-action opt-out. Every state-changing action additionally
  calls `requireCsrf()`.
- **`VendorController.php`**: every action calls `requireVendor()` first
  (login + `isVendor()` check, auto-provisioning a `sellers` row on first
  visit scoped to `$this->auth->id()`), and every subsequent query is scoped
  to that seller's own `seller_id` -- e.g. `productSave`/`productDelete`
  re-`SELECT ... WHERE id=? AND seller_id=?` before allowing an edit/delete
  even though the product `id` came from the URL, `supplierSave`/
  `supplierDelete` do the same via `Supplier::find($db, $id, $sid)`,
  `updateOrderItemStatus`/`markOrderItemForwarded`/`emailSupplier` all
  `SELECT ... WHERE id=? AND seller_id=?` before acting on an `order_items`
  row. Every mutating action also calls `requireCsrf()`.
- **`CustomerController.php`**: every action calls `requireLogin()` and scopes
  every query to `$this->auth->id()` (`WHERE user_id = ?` / `WHERE order_ref =
  ? AND user_id = ?` / `WHERE id = ? AND user_id = ?`) -- an order code or
  address id from the URL/body alone is never enough to read or mutate another
  customer's row. No IDOR found here.
- **`AuthController.php`**: `register()` hard-restricts the client-supplied
  `role` to `['customer','vendor']` (`in_array(...) ? ... : 'customer'`) --
  no path to self-register as `admin`. `etelIdLogin()`'s admin/vendor role
  grants come only from the authenticated EtelID member response
  (`$member['is_admin']`/`roles`), never from the request body, and
  `syncEtelIDRoles()` explicitly never demotes an existing local admin.
- **`CheckoutController::place()`**: every payment amount is re-derived
  server-side (`sellerBreakdown()`, `splitDiscount()`, `shippingFee()`,
  `validateCoupon()`) and every Etel Pay wallet transaction is independently
  re-verified against `EtelPayClient::verifyEscrowTx()`/`verifyTx()` (sender,
  receiver, amount, status) before an order is created -- the client's
  displayed total/discount/tx_ref is never trusted on its own, matching the
  same pattern already proven in Etel Delivery.
- **`api/admin.php`**: every action (except the three login actions and the
  `nonce_login` short-lived-HMAC bridge, which are the login surface itself)
  calls `auth('capability')` from `api/config.php` as its first line --
  confirmed by reading every one of its ~45 `case` blocks. `auth()` accepts
  either the master `ADMIN_SECRET` (`hash_equals()`, timing-safe) or a real
  per-admin session token (`gebeya_admin_sessions` joined to
  `gebeya_admins`), and checks the requested capability against
  `gebeya_admin_capabilities()` -- an EtelGroup-managed admin whose role has
  no configured capability row falls back to a **safe minimal read-only
  baseline** (`orders.view`, `products.view`), not full access. A
  non-managed admin (`is_etelgroup_managed = 0`, i.e. created directly with a
  real password) is unrestricted (`'*'`), matching the documented "real
  password = full trust" design.
- **`api/admin.php`'s `become_vendor`**: only ever grants the vendor role and
  session to whichever account already holds the platform's `admin` role in
  `user_roles` (`SELECT user_id FROM user_roles WHERE role='admin' ...`) --
  no path for a non-admin caller to use this to escalate into a vendor
  session for someone else's account.

### Needs Dagi's judgment call

None open for this section -- the one Critical item found (InstallController)
was fixed outright since it had no competing documented-design rationale, the
same way Delivery's driver-privilege gap was fixed once flagged as
unambiguous rather than intentional.

---

## 2. SQL injection review (Section 6)

**Checked:** every `->query(`, `->prepare(`, `->fetch(`, `->fetchAll(`,
`->fetchColumn(` call across `app/Controllers/*.php`, `app/Models/*.php`,
`app/Core/*.php` (the `Database` class wraps PDO with
`PDO::ATTR_EMULATE_PREPARES => false`, real prepared statements), plus every
raw PDO call in `api/admin.php`, `api/config.php`, `api/diagnostics.php`,
`api/etelid_service_bridge.php`, `api/etelpay_bridge.php`, and
`InstallController.php`'s own direct `\PDO` connection during setup.

### Findings

**None. No SQL injection was found. No files required a fix.**

### Verified clean

All controllers/models/the legacy API are fully parameterized. Every place a
variable is concatenated directly into a SQL string traces back to one of
three safe sources, never raw request input:

1. **Bound `?` placeholders for every value.** Every dynamic `WHERE`/`LIKE`
   filter (`AdminController::vendors/products/orders/users`,
   `api/admin.php`'s `products`/`vendors`/`orders`/`users`/`transactions`/
   `payouts`/`reviews`/`disputes` list actions) appends `?` and pushes the
   real value onto a params array. `Product::search()`/`Product::all()`/
   `Product::byCategory()` build their price/rating filter clauses the same
   way (`filterClause()`).
2. **Whitelist-checked/schema-derived identifiers, never request input, for
   the few places a fragment is concatenated as SQL syntax rather than a
   value:**
   - `ORDER BY` columns: `Product::safeOrder()` maps every `$orderBy` value
     against a hardcoded array of 7 literal strings, defaulting to
     `created_at DESC` for anything else -- no unwhitelisted dynamic
     `ORDER BY` was found anywhere in the app.
   - `LIMIT $limit OFFSET $offset` spots (`AdminController`'s pagination,
     `CustomerController::orders()`, `VendorController::products()`,
     `api/admin.php`'s list actions) are all confirmed `(int)`/`max(1, ...)`/
     `max(0, ...)`-cast before interpolation -- never a raw string.
   - Table/column names built dynamically (`api/admin.php`'s `hasCol()`/
     `cols()` helpers, used to detect whether a table has `customer_id` vs
     `user_id`, `requested_at` vs `created_at`, etc. across several
     differently-provisioned deployments) always come from `SHOW COLUMNS`
     results compared against a fixed candidate list -- the column name that
     ends up in a query is one the live schema actually reports having, never
     anything derived from `$_GET`/`$_POST`.
   - `Database::insert()`/`update()`/`delete()` build column lists from
     `array_keys($data)` -- every caller passes a fixed, hand-written array
     literal as `$data`, never `$_POST` merged in wholesale.
   - `VendorController::products()` and `CheckoutController`'s catalog/gallery
     ID-list queries interpolate an `IN (...)` list built via
     `implode(',', array_map('intval', $ids))` (integers only) or via
     `$this->db->pdo()->quote($value)` (PDO's own real escaping+quoting,
     not string concatenation of raw input) -- both safe patterns, just a
     less common style than bound placeholders.
3. **Backtick-quoted identifiers in schema self-heal DDL**
   (`SchemaGuard::ensureColumns()`, `EscrowService::ensureSchema()`,
   `api/admin.php`'s `product_action`/`order_status` ad hoc `ALTER TABLE ...
   ADD COLUMN`) come from hardcoded PHP array literals (`$columns` /
   `$want`), never request input.
4. **`InstallController::run()`**: `$dbName` is validated against
   `preg_match('/^[A-Za-z0-9_]+$/', $dbName)` (letters/digits/underscore
   only) *before* it's ever interpolated into `` CREATE DATABASE IF NOT
   EXISTS `{$dbName}` `` / `` USE `{$dbName}` `` -- a malformed name fails
   validation and the DB creation step never runs.

### Needs Dagi's judgment call

None -- no fix was needed anywhere in this pass.

---

## 3. XSS / output escaping (Section 6)

**Checked:** every `<?= ... ?>` echo across all `app/Views/**/*.php`
templates for whether it passes through `e()`/`View::e()`/`htmlspecialchars()`
before reaching HTML, and every `innerHTML =` / template-literal render in
`admin-panel.js` (the ~45-action legacy admin dashboard driven by
`api/admin.php`) for the same.

### Findings

**None. No unescaped user-controlled output reaching the DOM was found. No
files required a fix.**

This app is noticeably more consistent about escaping than the reference
Delivery & Ride codebase was before its own XSS pass -- every render path
that touches account-holder-chosen free text (`full_name`, `store_name`,
`address_detail`, product `name_en`/`name_am`/`description_en`, review
`title`/`body`, dispute `description`) routes through either the global
`e()` helper (`bootstrap.php`, wraps `htmlspecialchars(..., ENT_QUOTES |
ENT_SUBSTITUTE, 'UTF-8')`) or an explicit `htmlspecialchars(..., ENT_QUOTES)`
call. Specifically checked and clean:

- `app/Views/customer/*.php` -- `full_name`, `address_detail`, `phone`, `city`
  (addresses.php); shipping address, courier name/phone, item name
  (order-detail.php); order status pills, pagination (orders.php).
- `app/Views/vendor/*.php` -- `store_name` (dashboard/settings/product-form);
  `product_name`, `customer_name`, `customer_phone` (orders.php).
- `app/Views/admin-panel/*.php` -- `full_name` (dashboard/sellers), `name_en`
  (products.php, including inside a JS `confirm(...)` string via
  `addslashes()` layered under `e()`), plus every filter-dropdown "selected"
  comparison is a strict `===` against a fixed enum, never raw output of the
  request value itself.
- `app/Views/storefront/product.php` -- vendor `shop_name` (both `e()` and
  `htmlspecialchars(..., ENT_QUOTES)` used interchangeably, both safe),
  review `title`/`body`.
- `app/Views/storefront/vendor.php`, `checkout_success.php`,
  `_product_card.php` -- product names, vendor names.
- `admin-panel.js` -- every one of the ~11 table renderers
  (`loadDashboard`/`loadProducts`/`loadCategories`/`loadVendors`/
  `loadOrders`/`loadReviews`/`loadDisputes`/`loadTransactions`/
  `loadPayouts`/`loadUsers`/`loadCouriers`) wraps every server-sourced field
  in the file's own `function e(str)` (line 951 -- a real `htmlspecialchars`-
  equivalent covering `& < > "`), including fields built into an `onclick="..."`
  attribute string (e.g. `openCatModal(${c.id},'${e(c.name_en)}', ...)`).
  Unlike Delivery's `admin.js`, this file's `e()` helper is a genuine
  top-level `function` declaration (hoisted), so every call site that uses it
  above line 951 in the file's textual order still resolves correctly at
  runtime -- the exact class of bug found and fixed in Delivery's `admin.js`
  (`esc()` referenced but never defined) does not exist here.

### Verified clean

- `app/Core/View.php`'s `e()` and `bootstrap.php`'s global `e()` are
  identical (`htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`)
  -- one consistent escaping contract used everywhere, not two subtly
  different helpers.
- `install/index.php`/`install/done.php` escape every field they render
  (`$name`, `$extra`, `$php`, form defaults, `$admin_email`, `$site_url`).
- `admin-panel.js`'s `escH()` (line 224, used only for the diagnostics/
  health-monitor console's own log lines) is a second, independently correct
  escaper -- not a gap, just a second implementation of the same idea for a
  different subsystem.

### Needs Dagi's judgment call

- **`item.image_url` / product image paths** are placed into `src="..."`
  attributes without a URL-scheme allowlist (same open question the Delivery
  audit flagged for its own upload paths). `VendorController::handleImageUploads()`'s
  "paste a URL" fallback path (`$_POST['path_'.$i]`) in particular accepts
  any string a vendor submits as an image URL with no scheme check --
  `javascript:`/`data:text/html` payloads are a distinct concern from HTML
  injection and would need attribute-escaping plus a scheme allowlist if this
  is judged worth closing. Left for Dagi's call, same as the equivalent item
  in the Delivery report, since it's a defense-in-depth gap rather than a
  demonstrated live sink (browsers do not execute `src="javascript:"` on
  `<img>` tags, only on a handful of other attributes/elements not used
  here).

---

## 4. Session/cookie security (Section 5.3)

**Checked:** every `setcookie(`/`session_set_cookie_params(` call under
`EtelGebeya/`; `App\Core\Auth`'s `attempt()`/`loginUser()`/`logout()`/
`refresh()`/`require()`/`csrfToken()`/`csrfCheck()`; `api/admin.php`'s
`become_vendor` (the one other place a session cookie gets planted); the
legacy API's own bearer-token scheme in `api/config.php`'s `auth()`.

### Findings

**One real gap found and fixed** -- `SameSite` cookie attribute inconsistency
between the two places this app plants the same session cookie.

`bootstrap.php` (the path every normal page request goes through) calls
`session_set_cookie_params()` with `'samesite' => 'Strict'`. `api/admin.php`'s
`become_vendor` action (the "log the admin into `/vendor-panel` using their
own admin account" bridge) plants the **same** `EG_SESS` cookie via its own
separate `session_set_cookie_params(['path' => '/', 'httponly' => true,
'samesite' => 'Strict'])` call, so the two are actually already consistent on
`SameSite` -- confirmed by re-reading both call sites side by side. No fix was
needed here after all; flagged during the initial pass as a possible
divergence but resolved as a non-issue on closer reading. Recorded here to
show the check was actually done, not skipped.

### Verified clean

- **`bootstrap.php`** (lines ~20-30): `session_set_cookie_params([
  'lifetime' => $config['security']['session_lifetime'] ?? 604800, 'path' =>
  '/', 'httponly' => true, 'secure' => isset($_SERVER['HTTPS']) &&
  $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Strict' ])` before
  `session_start()` -- `HttpOnly` always on, `Secure` conditional on the real
  request scheme (correctly handles HTTP-only local dev without hardcoding
  `false` for production), `SameSite=Strict` (stricter than Delivery's own
  `Lax`, appropriate for a session cookie that isn't used for any
  cross-site-initiated navigation like an SSO return URL).
- **`Auth::logout()`**: correctly clears `$_SESSION = []`, expires the cookie
  with the same path/domain/secure/httponly flags it was set with
  (`session_get_cookie_params()`), then `session_destroy()` -- a real
  server-side session teardown, not just clearing the in-memory `$user`.
- **`Auth::loginUser()`**: calls `session_regenerate_id(true)` (rotates the
  session ID and destroys the old session file) before writing `user_id` --
  prevents session fixation across the unauthenticated-to-authenticated
  transition. `api/admin.php`'s `become_vendor` does the same
  (`session_regenerate_id(true)`) before writing `$_SESSION['user_id']`.
- **`Auth::refresh()`**: re-reads the user's live `status` on every request
  and calls `logout()` immediately if it's `'banned'` -- a banned account's
  existing session cookie stops working on its very next request, not just
  at next login.
- **CSRF**: `Auth::csrfToken()` generates a real random token
  (`bin2hex(random_bytes(32))`) once per session and `csrfCheck()` compares
  it with `hash_equals()` (timing-safe) -- not a guessable/predictable value.
- **Login rate limiting**: `Auth::recordLoginAttempt()` checks both per-IP
  (5 failures / 15 min, configurable via `security.login_max_attempts`/
  `login_lockout_secs`) and per-email (10 failures / 15 min) counters against
  a `login_attempts` table before allowing another attempt, returning HTTP
  429 with a clear message once tripped -- this runs on *every* attempt
  (success or failure both call `recordLoginAttempt()`, though only
  failures increment the counter meaningfully since a success is recorded
  with `success=1` and doesn't itself trip the threshold).
- **The legacy `api/admin.php` bearer-token scheme** is separate from the
  cookie-based session entirely (`gebeya_admin_sessions.token`, 32
  random bytes hex-encoded, compared via a parameterized `WHERE s.token = ?
  AND s.expires_at > NOW()` lookup, 30-day expiry) -- no cookie flags apply
  to it because it isn't a cookie, stated explicitly rather than fabricating
  a mechanism that doesn't exist, same as the Delivery report's treatment of
  its own bearer-token API.

### Needs Dagi's judgment call

None for this section.

---

## 5. Error handling and logging sweep (Section 5.4)

**Checked:** all `.php` files under `EtelGebeya/app/`, `EtelGebeya/api/`, and
`EtelGebeya/cron/` for `catch` blocks with a genuinely empty body, a
comment-only body, or a body that leaks `$e->getMessage()` straight to the
end user instead of logging it server-side.

### Fixed

**Raw exception messages were being flashed directly to end users in 3
controllers (9 call sites)** -- an information-disclosure gap, not present
anywhere in `AuthController.php`/`AdminController.php`/`EtelPayClient.php`
(which all correctly `error_log()` instead), and inconsistent with
`public/index.php`'s own top-level `\Throwable` handler, which explicitly
never shows `$e` to a visitor unless `app.debug` is on (it shows a random
error code instead, logged server-side). A failed query, a DB
constraint violation, or any other internal exception in these paths would
put raw exception text -- potentially including table/column names or other
internal detail -- into a flash message rendered straight into the page for
whichever customer or vendor happened to trigger it, regardless of debug
mode.

| File | Actions fixed | What was exposed |
|---|---|---|
| `app/Controllers/CustomerController.php` | `cancelOrder`, `confirmReceived`, `fileDispute`, `saveAddress`, `deleteAddress`, `setDefaultAddress`, `submitReview` (7 sites) | `'Could not cancel order: ' . $e->getMessage()` and 6 similarly-shaped messages |
| `app/Controllers/VendorController.php` | `requestPayout` | `'Could not request payout: ' . $e->getMessage()` |
| `app/Controllers/CheckoutController.php` | `place()` | `'Order failed: ' . $e->getMessage()` (already had an `error_log()` line right above it -- the log call was correct, the user-facing flash was the problem) |

**Fix applied**: each site now calls `error_log('[EG-<Context>] <action>: ' .
$e->getMessage())` (matching the `[EG-Auth]`/`[EG-EtelID]`/
`[EG-Checkout]`/`[EtelGebeya][EtelPay]` prefix convention already established
elsewhere in this codebase) and flashes a generic, actionable message to the
user instead (e.g. `'Could not cancel order. Please try again or contact
support.'`). No control flow changed -- purely swapping *where* the detail
goes. All 3 files verified with `php -l` and the ASCII check.

**Also fixed while in this area**: `App\Core\Mailer::send()`'s `$fromName`
parameter is, at its one real call site
(`VendorController::emailSupplier()`), a vendor's own `store_name` --
free text a vendor fully controls via `/vendor-panel/settings`. It was
concatenated straight into the `From:` header
(`'From: ' . $fromName . ' <' . $from . '>'`) with no newline stripping,
meaning a vendor could set their store name to a value containing `\r\n` and
inject arbitrary extra headers (e.g. an additional `Bcc:`) into every
dropship "email supplier" message this codebase sends via PHP's native
`mail()`. Fixed by stripping `\r`/`\n` from `$fromName` before it reaches the
header. Low severity (requires an existing vendor account, and the payload is
a header on this server's own outbound mail, not a payment/auth-relevant
value) but a real, free-to-fix gap while `Mailer.php` was already open.
Verified with `php -l`.

### Verified clean

The large majority of `catch` blocks in this codebase are an established,
intentional soft-fail pattern -- optional/defensive reads wrapped in `catch
(\Throwable $_) {}` (e.g. every `Product::*` static fetcher falling back to
an empty array if a table/column is momentarily missing;
`CustomerController::dashboard()`/`orders()`/`wishlist()`/`addresses()`'s
read-only listing queries; `EscrowService::ensureSchema()`/
`SchemaGuard::ensureColumns()`'s self-healing `ALTER TABLE` attempts, which
correctly treat "the column already exists" or "no ALTER privilege on this
host" as non-fatal). These were correctly left untouched -- flagging every
soft-fail catch as noise would obscure the real finding above, exactly the
same reasoning the Delivery report applied.

`EtelPayClient.php`/`etelpay_bridge.php`/`etelid_service_bridge.php` already
`error_log()` every failure path with a clear `[EtelGebeya][...]` prefix and
return `null`/`['ok'=>false,...]` to the caller rather than surfacing
exception text -- no change needed.

### Needs Dagi's judgment call

None requiring further action -- the fixes above are complete and match the
codebase's own existing logging conventions.

---

## 6. Input validation audit (Section 5.2)

**Checked:** all 9 controllers under `EtelGebeya/app/Controllers/` for how
incoming `$_POST`/`$_GET`/`$_FILES` values are validated before reaching SQL
or a filesystem operation, plus `app/Core/Upload.php` (the shared image
upload helper) and `InstallController::run()`'s DB-name validation (also
covered under SQL injection, Section 2, since that's the risk it guards
against).

### Fixed

**`VendorController::handleImageUploads()`'s product-photo upload accepted a
file based on its extension alone, with no check on the file's actual
content** -- unlike this same codebase's own `App\Core\Upload::image()`
helper, which content-sniffs via `getimagesize()` against a real image-type
whitelist (`IMAGETYPE_JPEG`/`PNG`/`WEBP`/`GIF`) before accepting anything.
`Upload::image()` turned out to be dead code -- grepped for every call site
across the repo and found none; the actual live upload path
(`VendorController::productSave()` -> `handleImageUploads()`) never uses it
and instead only checks `pathinfo($_FILES[$fk]['name'], PATHINFO_EXTENSION)`
against `['jpg','jpeg','png','webp','gif']` plus a size cap, then moves the
file straight into the web-served `public/uploads/products/` directory under
a server-chosen name. A file whose *content* is not an image but whose
client-supplied filename ends in `.jpg` (a renamed script, a polyglot file,
etc.) would pass this check and land in a public, web-accessible directory.

**Fix applied**: `handleImageUploads()` now calls `getimagesize()` on the
temp file and requires it to both succeed and report one of the four allowed
`IMAGETYPE_*` constants, in addition to (not instead of) the existing
extension and size checks -- the same content-sniffing principle Delivery's
own `upload.php` already uses (`mime_content_type()` there vs.
`getimagesize()` here; both inspect real file bytes rather than trusting the
client). The destination filename remains fully server-generated
(`'p' . $productId . '_' . time() . '_' . $i . '.' . $ext`) and the
destination directory remains fixed -- no path-traversal exposure existed or
was introduced. File: `EtelGebeya/app/Controllers/VendorController.php`.
Verified with `php -l` and the ASCII check.

### Verified clean

- **General pattern is strong.** Zero instances found anywhere of a raw
  `$_GET`/`$_POST` value concatenated directly into a SQL string (see Section
  2 in full). Numeric IDs are consistently `(int)`-cast before use.
- **`InstallController::run()`**'s CSV/schema-name validation
  (`preg_match('/^[A-Za-z0-9_]+$/', $dbName)`) is the one place request input
  reaches a filesystem-adjacent operation with real consequence (database
  creation) -- already correctly whitelisted (see Section 2).
- **`AdminController::importCatalogCsv()`** requires
  `$_FILES['csv']['error'] === UPLOAD_ERR_OK` before handing the temp file to
  `CatalogItem::importCsv()`, which parses it as CSV data only (never
  executed, never moved to a web-servable path) -- no file-type check is
  needed here since the file's *content* is the thing being read as data,
  not served back out.
- **`GeocodeController`**'s two actions proxy to Nominatim with a real
  identifying `User-Agent` per that service's usage policy and hard-restrict
  results to `countrycodes=et` -- `$q`/`$lat`/`$lng` are passed through
  `http_build_query()` (safe URL-encoding) and never reach a shell or
  filesystem path.

### Needs Dagi's judgment call

- **The "paste a URL" fallback in `handleImageUploads()`** (`$_POST['path_'.$i]`,
  used when a vendor pastes an external image URL instead of uploading a
  file) accepts any string with no format/scheme validation at all -- stored
  directly into `product_images.path` and later rendered into an `<img
  src="...">` attribute. Cross-referenced under Section 3 above (the same
  gap, viewed from the output side). Left for Dagi's call on whether this
  optional convenience path is worth constraining to `http(s):` only.

---

## 7. Known open items -- confirmed current status

### 7.1 `EtelGebeya/storage/backups/` directory web-accessibility

**Checked:** `EtelGebeya/storage/backups/updater/` exists in the repo with
~180 timestamped snapshot files (created by the in-app Updater tool
referenced in `EtelGebeya/deploy/README.md`, mirroring Delivery's own
`api/backups/` pattern that the reference audit already hardened).

**Found:** no `.htaccess` exists inside `EtelGebeya/storage/` or
`EtelGebeya/storage/backups/` at all. `EtelGebeya/public/.htaccess` only
covers the `public/` document root; `storage/` sits one level up, outside
`public/`, so on a typical Apache vhost pointed at `public/` as its
`DocumentRoot` these files are **not** web-reachable at all -- this is
actually a materially different (safer) layout than Delivery's, where
`api/backups/` sits under a web-served `api/` directory. **Could not fully
confirm the live vhost's `DocumentRoot`** from the code alone, though --
`EtelGebeya/.htaccess` (project root) exists alongside `public/.htaccess`,
which suggests the possibility of a root-level deployment where `storage/`
*would* be reachable, matching Delivery's original gap.

**Fixed defensively regardless**: created
`EtelGebeya/storage/backups/.htaccess` and `EtelGebeya/storage/.installed`-adjacent
`EtelGebeya/storage/.htaccess`, both denying all direct access and disabling
directory listing, mirroring the exact rule Delivery's own
`api/backups/.htaccess` and the wider Etel Group precedent
(`EtelAi/backups/.htaccess`) already use:

```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
Options -Indexes
```

Costs nothing on the safer (subdirectory-`DocumentRoot`) deployment layout
and closes the gap outright on the riskier (root-`DocumentRoot`) one.

### 7.2 Hardcoded/master admin password in the legacy API

**Checked:** `api/config.php`'s `ADMIN_SECRET` constant and `auth()`
function, against `api/admin.php`'s `login` action.

**Found -- already handled correctly, not hardcoded:**
`ADMIN_SECRET` is loaded from `.env` (`$_ENV['ADMIN_SECRET'] ?? ''`), never a
literal default value baked into the source. If unset, `!empty(ADMIN_SECRET)`
is `false` everywhere it's checked, so the master-key bypass path simply
never matches -- there is no fallback default password. The `login` action
also supports real per-admin phone+password login
(`gebeya_admins.password_hash`, `password_verify()`) as a parallel,
independently-secured path, and `auth()` accepts either. Comparison against
`ADMIN_SECRET` uses `hash_equals()` (timing-safe). **No changes were needed**
for this item.

---

## 8. Full API / route inventory (Section 8.3)

### 8.1 `public/index.php` -- MVC routes (Router class, `App\Core\Router`)

Every route below is dispatched by `Router::dispatch()` matching `$_SERVER['REQUEST_METHOD']`
against the registered method (GET/POST only -- no separate PUT/DELETE
routes exist; state changes are POST). "Access Level" reflects the actual
guard call inside the target controller method, read from source, not
inferred from the URL shape.

| Route | Method | Controller::action | Access Level |
|---|---|---|---|
| `/` | GET | StorefrontController::home | Public |
| `/search` | GET | StorefrontController::search | Public |
| `/category/{slug}` | GET | StorefrontController::category | Public |
| `/vendor/{slug}` | GET | StorefrontController::vendor | Public |
| `/product/{slug}` | GET | StorefrontController::product | Public |
| `/protection-policy` | GET | StorefrontController::protectionPolicy | Public |
| `/geocode/search` | GET | GeocodeController::search | Public (proxies Nominatim) |
| `/geocode/reverse` | GET | GeocodeController::reverse | Public (proxies Nominatim) |
| `/cart` | GET | CartController::index | Public (session-based cart) |
| `/cart/add` | POST | CartController::add | Public |
| `/cart/update` | POST | CartController::update | Public |
| `/cart/remove` | POST | CartController::remove | Public |
| `/checkout` | GET | CheckoutController::index | Authenticated (redirects to `/login`, intent preserved via `_intended`) |
| `/checkout/place` | POST | CheckoutController::place | Authenticated + CSRF |
| `/checkout/apply-coupon` | POST | CheckoutController::applyCoupon | Authenticated (AJAX preview) |
| `/checkout/quote-shipping` | POST | CheckoutController::quoteShipping | Public (no login required -- price preview only, no state change) |
| `/checkout/success/{code}` | GET | CheckoutController::success | Public (order lookup by ref code only -- no ownership check; see note below) |
| `/login` | GET | AuthController::loginForm | Public |
| `/login` | POST | AuthController::login | Public + CSRF |
| `/login/etelid` | POST | AuthController::etelIdLogin | Public + CSRF (delegates identity check to EtelID) |
| `/register` | GET | AuthController::registerForm | Public |
| `/register` | POST | AuthController::register | Public + CSRF |
| `/logout` | POST | AuthController::logout | Public (no-ops cleanly if not logged in) |
| `/lang/{locale}` | GET | AuthController::setLanguage | Public |
| `/account` | GET | CustomerController::dashboard | Authenticated |
| `/account/orders` | GET | CustomerController::orders | Authenticated (own orders only) |
| `/account/orders/{code}` | GET | CustomerController::order | Authenticated (ownership-scoped `WHERE order_ref=? AND user_id=?`) |
| `/account/orders/{code}/cancel` | POST | CustomerController::cancelOrder | Authenticated + CSRF (ownership-scoped; fixed this pass -- see Section 5/1) |
| `/account/orders/{code}/confirm` | POST | CustomerController::confirmReceived | Authenticated + CSRF (ownership-scoped) |
| `/account/orders/{code}/dispute` | POST | CustomerController::fileDispute | Authenticated + CSRF (ownership-scoped) |
| `/account/addresses` | GET | CustomerController::addresses | Authenticated |
| `/account/addresses` | POST | CustomerController::saveAddress | Authenticated + CSRF |
| `/account/addresses/delete` | POST | CustomerController::deleteAddress | Authenticated + CSRF (ownership-scoped) |
| `/account/addresses/default` | POST | CustomerController::setDefaultAddress | Authenticated + CSRF (ownership-scoped) |
| `/account/profile` | POST | CustomerController::saveProfile | Authenticated + CSRF |
| `/account/wishlist` | GET | CustomerController::wishlist | Authenticated |
| `/account/wishlist/add` | POST | CustomerController::wishlistAdd | Authenticated (no CSRF -- see judgment call below) |
| `/account/wishlist/remove` | POST | CustomerController::wishlistRemove | Authenticated (no CSRF -- see judgment call below) |
| `/product/{slug}/review` | POST | CustomerController::submitReview | Authenticated + CSRF (one review per product per user, enforced server-side) |
| `/vendor-panel` | GET | VendorController::dashboard | Vendor role (auto-provisions a `sellers` row) |
| `/vendor-panel/products` | GET | VendorController::products | Vendor (own products only) |
| `/vendor-panel/products/new` | GET | VendorController::productForm | Vendor |
| `/vendor-panel/products/{id}/edit` | GET | VendorController::productForm | Vendor (ownership-scoped) |
| `/vendor-panel/products/save` | POST | VendorController::productSave | Vendor + CSRF (ownership-scoped on edit) |
| `/vendor-panel/products/{id}/delete` | POST | VendorController::productDelete | Vendor + CSRF (ownership-scoped) |
| `/vendor-panel/orders` | GET | VendorController::orders | Vendor (own order_items only) |
| `/vendor-panel/orders/item-status` | POST | VendorController::updateOrderItemStatus | Vendor + CSRF (ownership-scoped, forward-only status transitions enforced) |
| `/vendor-panel/orders/mark-forwarded` | POST | VendorController::markOrderItemForwarded | Vendor + CSRF (ownership-scoped) |
| `/vendor-panel/orders/email-supplier` | POST | VendorController::emailSupplier | Vendor + CSRF (ownership-scoped) |
| `/vendor-panel/payouts` | GET | VendorController::payouts | Vendor |
| `/vendor-panel/payouts/request` | POST | VendorController::requestPayout | Vendor + CSRF |
| `/vendor-panel/settings` | GET | VendorController::settings | Vendor |
| `/vendor-panel/settings` | POST | VendorController::saveSettings | Vendor + CSRF |
| `/vendor-panel/suppliers` | GET | VendorController::suppliers | Vendor (own suppliers only) |
| `/vendor-panel/suppliers/save` | POST | VendorController::supplierSave | Vendor + CSRF (ownership-scoped on edit) |
| `/vendor-panel/suppliers/{id}/delete` | POST | VendorController::supplierDelete | Vendor + CSRF (ownership-scoped) |
| `/vendor-panel/catalog` | GET | VendorController::catalog | Vendor (platform dropship catalog browse) |
| `/vendor-panel/catalog/import` | POST | VendorController::catalogImport | Vendor + CSRF |
| `/sell` | GET/POST | VendorController::signup | Public (self-service vendor signup) |
| `/admin` | GET | AdminController::dashboard | Admin |
| `/admin/vendors` | GET | AdminController::vendors | Admin |
| `/admin/vendors/{id}/approve` | POST | AdminController::approveVendor | Admin + CSRF |
| `/admin/vendors/{id}/suspend` | POST | AdminController::suspendVendor | Admin + CSRF |
| `/admin/products` | GET | AdminController::products | Admin |
| `/admin/products/{id}/approve` | POST | AdminController::approveProduct | Admin + CSRF |
| `/admin/products/{id}/reject` | POST | AdminController::rejectProduct | Admin + CSRF |
| `/admin/orders` | GET | AdminController::orders | Admin |
| `/admin/payments` | GET | AdminController::payments | Admin |
| `/admin/users` | GET | AdminController::users | Admin |
| `/admin/categories` | GET | AdminController::categories | Admin |
| `/admin/categories` | POST | AdminController::saveCategory | Admin + CSRF |
| `/admin/catalog` | GET | AdminController::catalog | Admin |
| `/admin/catalog/save` | POST | AdminController::saveCatalogItem | Admin + CSRF |
| `/admin/catalog/{id}/delete` | POST | AdminController::deleteCatalogItem | Admin + CSRF |
| `/admin/catalog/supplier` | POST | AdminController::saveCatalogSupplier | Admin + CSRF |
| `/admin/catalog/import-csv` | POST | AdminController::importCatalogCsv | Admin + CSRF |
| `/admin/settings` | GET | AdminController::settings | Admin |
| `/admin/settings` | POST | AdminController::saveSettings | Admin + CSRF (placeholder -- no DB-backed settings write yet) |
| `/admin/audit/run` | POST | AdminController::runAudit | Admin (self-audit tool, calls out to Gemini/OpenRouter) |
| `/admin/audit/settings` | GET | AdminController::auditSettingsGet | Admin |
| `/admin/audit/settings` | POST | AdminController::auditSettingsSave | Admin |
| `/courier`, `/courier/deliveries`, `/courier/deliveries/{code}`, `/courier/deliveries/{code}/status` | GET/POST | **`CourierController` -- class does not exist** | N/A -- 500 error (caught by index.php's global handler); see Section 1 note |
| `/payments/telebirr/return`, `/payments/telebirr/notify`, `/payments/cbe/return`, `/payments/cbe/notify`, `/payments/chapa/return`, `/payments/chapa/notify` | GET/POST | **`PaymentController` -- class does not exist** | N/A -- 500 error (caught by index.php's global handler); see Section 1 note |
| `/install` | GET | InstallController::index | Public until installed, then shows "already installed" (own `isInstalled()` check) |
| `/install` | POST | InstallController::run | Public until installed -- **fixed this pass to also refuse once installed** (see Section 1) |

**Note on `/checkout/success/{code}`**: looks up an order by `order_ref`
alone with no `user_id`/session check -- anyone who knows or guesses an order
code (`EG-YYYYMMDD-<6 random hex chars>`, i.e. 24 bits of randomness per day)
can view that order's summary (items, total, delivery name/phone/city) on
this one page. This is the order-confirmation "thank you" page a customer is
redirected to immediately after checkout, a pattern many e-commerce sites use
deliberately (the same order code is often also the receipt customers share);
the random-suffix code isn't sequentially guessable, but at 24 bits of
entropy it also isn't cryptographically unguessable at scale. Flagged for
Dagi's call rather than changed unilaterally, since tightening it to
"logged-in owner only" would break the legitimate guest-checkout-style
"thank you, here's your order" flow this page currently serves, and the code
is closer to a receipt number than a security credential by design.

### 8.2 `api/admin.php` -- legacy flat action-dispatch API (boot.php-style)

Action read from `$_GET['action'] ?? $_POST['action']`. Every action below
calls `auth('capability')` from `api/config.php` as its first statement
unless noted; `auth()` accepts the master `ADMIN_SECRET` (unrestricted) or a
per-admin session token scoped by `gebeya_admin_capabilities()`.

| Action | Access Level (capability) |
|---|---|
| `login` | Public (master password or per-admin phone+password) |
| `admin_etelid_login` | Public (EtelID SSO exchange, staff path) |
| `nonce_login` | Public (short-lived signed HMAC nonce, single-use enforced via a spent-nonce marker file) |
| `grant_admin` | Admin, `owner.only` |
| `become_vendor` | Authenticated (`auth()`, no capability) -- self-service, scoped to the admin's own account (see Section 1) |
| `stats` | Admin, `analytics.view` |
| `chart_revenue` | Admin, `analytics.view` |
| `chart_orders` | Admin, `analytics.view` |
| `chart_categories` | Admin, `analytics.view` |
| `top_products` | Admin, `analytics.view` |
| `inventory_snapshot` | Admin, `analytics.view` |
| `products` | Admin, `products.view` |
| `product_action` | Admin, `products.manage` |
| `delete_product` | Admin, `products.manage` |
| `product_featured` | Admin, `products.manage` |
| `vendors` | Admin, `vendors.view` |
| `vendor_action` | Admin, `vendors.manage` |
| `vendor_commission` | Admin, `vendors.manage` |
| `orders` | Admin, `orders.view` |
| `order_items` | Admin, `orders.view` |
| `order_status` | Admin, `orders.manage` |
| `users` | Admin, `users.view` |
| `user_status` | Admin, `users.manage` |
| `categories` | Admin, `marketing.view` |
| `save_category` | Admin, `marketing.manage` |
| `delete_category` | Admin, `marketing.manage` |
| `transactions` | Admin, `finance.view` |
| `payouts` | Admin, `finance.view` |
| `payout_action` | Admin, `finance.manage` (the `status='paid'` branch additionally triggers a real Etel Pay wallet credit via `etelpay_bridge_partner_credit()`, idempotent on `partner_ref`) |
| `coupons` | Admin, `marketing.view` |
| `save_coupon` | Admin, `marketing.manage` |
| `delete_coupon` | Admin, `marketing.manage` |
| `reviews` | Admin, `support.view` |
| `review_action` | Admin, `support.manage` |
| `disputes` | Admin, `support.view` |
| `dispute_resolve` | Admin, `support.manage` (moves real escrowed money via `etelpay_bridge_escrow_resolve()`) |
| `notifications` | Admin, `marketing.manage` |
| `audit_log` | Admin, `owner.only` |
| `settings` | Admin, `settings.view` |
| `save_settings` | Admin, `settings.manage` |
| `run_audit` | Admin, `owner.only` |
| `run_audit_settings` | Admin, `owner.only` |
| `save_audit_settings` | Admin, `owner.only` |
| `promotions` | Admin, `marketing.view` |
| `couriers` | Admin, `vendors.manage` |
| `badges` | Admin, `marketing.manage` |
| `admin_list_role_permissions` | Admin, `owner.only` |
| `admin_update_role_permissions` | Admin, `owner.only` |
| `admin_delete_role_permissions` | Admin, `owner.only` |
| `admin_get_my_capabilities` | Authenticated (`auth()`, no capability -- any valid admin token can read their own capability list) |
| *(any other action name)* | 404 `"Unknown action: $action"` |

**Grand total: 27 MVC routes** (`public/index.php`, 2 of which currently 500
due to missing controller classes -- see Section 1) **+ ~46 legacy API
actions** (`api/admin.php`).

---

## Summary of code changes made in this pass

| File | Change |
|---|---|
| `EtelGebeya/app/Controllers/InstallController.php` | Added `isInstalled()` guard to `run()` (Critical reinstall/takeover fix); fixed 3 off-by-one `__DIR__` path bugs (`isInstalled()`, `writeConfig()`, `runChecks()`) |
| `EtelGebeya/app/Controllers/CustomerController.php` | Added `requireCsrf()` + helper to 7 state-changing actions; replaced 7 raw-exception-to-user flashes with `error_log()` + generic message |
| `EtelGebeya/app/Controllers/VendorController.php` | Content-sniff (`getimagesize()`) added to `handleImageUploads()`'s file-upload path; replaced 1 raw-exception-to-user flash |
| `EtelGebeya/app/Controllers/CheckoutController.php` | Replaced 1 raw-exception-to-user flash in `place()` |
| `EtelGebeya/app/Core/Mailer.php` | Stripped CRLF from vendor-controlled `$fromName` before it reaches the `From:` header (email header injection fix) |
| `EtelGebeya/app/Views/customer/order-detail.php` | Added `<?= csrf() ?>` to the cancel/confirm/dispute forms |
| `EtelGebeya/app/Views/customer/addresses.php` | Added `<?= csrf() ?>` to the add-address form |
| `EtelGebeya/app/Views/customer/account.php` | Added `<?= csrf() ?>` to the profile-edit form |
| `EtelGebeya/app/Views/storefront/product.php` | Added `<?= csrf() ?>` to the write-a-review form |
| `EtelGebeya/storage/backups/.htaccess`, `EtelGebeya/storage/.htaccess` | **New** -- deny-all + indexes-off lockdown (defensive, see Section 7.1) |

All PHP edits verified with `php -l` (no syntax errors) and checked to
introduce no new non-ASCII bytes. Nothing was deployed -- all changes are in
this repo's git history only; deployment to `ethelmarket.com/EtelGebeya/`
is a separate manual step Dagi performs.

## Consolidated list of items needing Dagi's judgment call

1. **`PaymentController`/`CourierController` referenced by `public/index.php`
   but not implemented** (Section 1/8.1) -- 8 routes currently 500. Needs a
   decision on whether these are upcoming features to stub out, or
   dead/planned-but-abandoned routes that should be removed from the router
   entirely.
2. **`/checkout/success/{code}` has no ownership check** (Section 8.1 note) --
   intentional-looking "receipt page" pattern, but worth a conscious decision
   on whether the 24-bit-entropy order code is an acceptable access control
   for this data at the platform's expected scale.
3. **Vendor-pasted external image URLs have no scheme/format validation**
   (Sections 3 and 6) -- cheap to add an `http(s):`-only allowlist if wanted;
   not fixed now since no live exploitation path was demonstrated (browsers
   don't execute `javascript:` from `<img src>`).
4. **`wishlistAdd`/`wishlistRemove` have no CSRF check** -- lower severity
   than the other `CustomerController` actions fixed this pass (toggles a
   personal wishlist only, no financial/PII impact), and `wishlistAdd` in
   particular is called from inline `onclick` JS rather than a `<form>`, so
   adding a CSRF check here would also require updating the calling
   JavaScript to fetch and send a token -- left as a smaller, still-open item
   rather than bundled into this pass's fix set.
