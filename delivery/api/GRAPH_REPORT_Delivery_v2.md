# GRAPH_REPORT.md — ETEL Delivery / Ride — Full System (v2)

Traced by hand from the newly-uploaded full `delivery.zip` (not
Graphify-generated — same hand-tracing method used throughout this
project, cross-checking `api/boot.php`'s actual routing table, each
controller's own `require` calls, and real JS `action:` string literals
rather than assuming from file names).

**This is a substantial rewrite, not a light update.** The system has
grown considerably since the original pass: **7 controllers → 14**,
**223 actions → 311**, **4 shared `lib/` files → 8**, and five entirely
new subsystems (Equb, Investor Portal, Platform Economics, Planning,
System Reset) that didn't exist before. Two real, live bugs were also
found and fixed in the course of this session (see §9).

---

## 1. Request path, end to end (unchanged in shape)

```
Browser page (*.html)
  └─ loads → CSS files, JS bundle(s)
       └─ JS calls api('action', {...}) / fetch(ETEL.api, {action:...})
            └─ api.php
                 └─ require boot.php
                      └─ switch ($action) { ... }
                           ├─ ~13 actions handled inline in boot.php itself
                           └─ 298 actions dispatched to one of 14 controllers
                                └─ controller optionally require_once's lib/*.php
```

**One real architectural change worth flagging:** `lib/hexgrid.php` is
now loaded **unconditionally** at the top of `boot.php` (line 61), not
lazily per-action like every other lib file. Given hex-grid math is
genuinely small and cheap, this is a reasonable, deliberate exception
rather than an oversight - but it does mean hexgrid.php is the one file
that no longer follows this project's own "never pre-load a controller,
never pre-load a lib file" discipline, worth remembering if that
discipline ever gets enforced via a linter or code review checklist.

`311` is the total count of `case '...'` labels in the switch - a small
number of these are aliases for the same underlying action (e.g.
`reset_request` / `send_reset_otp`), so the true count of *distinct*
behaviors is slightly lower; not re-verified label-by-label this pass.

---

## 2. Backend — controllers & lib (current state)

| Controller | Lines | Actions | Requires `lib/` |
|---|---|---|---|
| `RideController.php` | not re-measured | **67** | `schema_helpers.php`, `ride_fare.php` |
| `AdminController.php` | not re-measured | **84** | `app_releases.php`, `schema_helpers.php`, `ride_fare.php` |
| `OrderController.php` | not re-measured | **36** | `delivery_order_core.php` |
| `EquityController.php` | 504 | **18** | none found this pass |
| `EqubController.php` | 500 | **19** | `wallet_ops.php`, `equb_processing.php` |
| `ServiceGiverController.php` | not re-measured | **19** | none found this pass |
| `AuthController.php` | not re-measured | **19** | `schema_helpers.php` |
| `AgentController.php` | not re-measured | **9** | `ride_fare.php`, `delivery_pricing.php`, `delivery_order_core.php` |
| `PlanningController.php` | 169 | **7** | none found this pass |
| `SystemResetController.php` | 226 | **3** | none found this pass |
| `PlatformEconomicsController.php` | 116 | **3** | none found this pass |
| `DriverFuelController.php` | 113 | **4** | none found this pass |
| `CourierFuelController.php` | 91 | **4** | none found this pass |
| `PushController.php` | not re-measured | **4** | none |
| `InvestorPortalController.php` | 104 | **2** (many more likely handled via internal branching within these 2 - see §3) | none |
| **boot.php inline** | — | **~13** | never touches a controller file |

**223 → 311 actions.** `RideController` grew from 55→67, `AdminController`
from 64→84, `OrderController` from 31→36 - all organic growth of
existing systems. The five new controllers below account for the rest.

### lib/ — shared layer (now 8 files, was 4)

| lib file | Required by | Notes |
|---|---|---|
| `hexgrid.php` | `boot.php` (unconditional) | See the callout in §1 - loaded once per request regardless of action, not lazily. |
| `schema_helpers.php` | `AdminController`, `AuthController`, `RideController` | Same wide-blast-radius shared-column-helper role as before. `AuthController` is a new caller since the last pass. |
| `ride_fare.php` | `AdminController`, `AgentController`, `RideController`, `boot.php` | Unchanged wide-blast-radius file - still the one to grep every caller of before touching. |
| `delivery_order_core.php` | `AgentController`, `OrderController`, `boot.php` | Unchanged core delivery-flow file. |
| `delivery_pricing.php` | `AgentController` | Unchanged. |
| `app_releases.php` | `AdminController`, `boot.php` | Powers the canary/staged-rollout release system (versioned bundle folders, admin Releases panel) already built in an earlier session. |
| `equb_processing.php` | `EqubController` | **New.** The actual per-round debit/payout logic for the Digital Equb system - see §4. |
| `wallet_ops.php` | `EqubController` | **New.** Shared wallet debit/credit primitives, used by Equb's automatic per-round money movement. |

---

## 3. Five new subsystems since the last pass

### Digital Equb (rotating savings/credit association)

`EqubController.php` (500 lines, 19 actions) digitizes the traditional
Ethiopian community savings model. Real design details worth knowing:

- **Admin-gated by design, not by accident.** A person must be
  explicitly marked `permitted` in `equb_participants` before they can
  create or join any group - mirrors the same access-control philosophy
  already used for investors (see below). The controller's own comment
  is explicit about this being a deliberate mirror of that model.
- **Real money moves automatically.** Per-round debits and payouts run
  through `lib/wallet_ops.php` / `lib/equb_processing.php`, shared with
  a separate `equb_cron.php` (a scheduled job, not user-triggered) -
  meaning this isn't just bookkeeping, it's live financial movement on
  a schedule. Worth extra scrutiny/testing given that combination
  (automatic + financial + cron-triggered) is exactly the shape of bug
  that's expensive when it's wrong.
- Depends on a specific migration (`041_equb_participants.sql`) having
  been applied - the controller fails loudly with a specific message
  telling the admin exactly that if the table doesn't exist yet, rather
  than a generic SQL error. Good defensive practice, worth replicating
  elsewhere in this codebase if it isn't already the norm.

### Investor Portal

`InvestorPortalController.php` (104 lines) is a genuinely different
auth model from everything else in this system: **not gated by
`require_admin()` at all.** Investors aren't staff and have no admin
login - every action instead requires the investor's own long random
`access_token`. What an investor can see beyond their basic stake (cap
table, distribution history, growth planner) is controlled per-investor
via three flags (`can_view_captable`, `can_view_distributions`,
`can_view_growth_planner`) set by the founder in the admin Equity tab -
this controller only ever *reads* those flags, never grants access
itself. Clean separation of "who decides access" (admin Equity tab)
from "who enforces it" (this controller) - worth keeping that
separation intact if this ever gets extended.

Only 2 case labels were found routing here directly - the small action
count doesn't mean a small feature; it likely means most of the surface
area is internal branching within those 2 actions rather than one
action per operation. Not traced deeper this pass.

### System Reset — the "DANGER ZONE"

`SystemResetController.php` (226 lines, 3 actions) is explicitly
labeled in its own header comment as **"the single most destructive
action in the entire admin system"** - can permanently delete real
orders, rides, ratings, and user accounts. Good, deliberate safety
design already in place: gated by a `system.reset` capability that is
**explicitly excluded from a generic Owner grant by default** and must
be added separately in the Roles panel. This is a real, positive
security finding worth calling out on its own, not just a neutral
architecture note - it's exactly the kind of "the blast radius should
require an extra deliberate step" design this whole project's security
work has been pushing toward elsewhere (rate limiting, session hashing,
capability gating).

### Platform Economics & Planning

Two smaller, related controllers (116 and 169 lines) backing the
existing Platform Economics / Business Plan / Payroll admin tools
already built in earlier sessions (per project history: payroll
tooling, investor equity chart, capital-requirement calculator
combining Payroll + Platform Economics + Equity data). Not deeply
re-traced this pass - flagged here mainly so their existence and rough
size is on record for whoever scopes the next session touching them.

### Driver Fuel / Courier Fuel

Two small, near-identical controllers (113 and 91 lines, 4 actions
each) - almost certainly fuel-allowance or fuel-cost tracking per
driver/courier, parallel structure suggesting one was built by copying
the other. Not traced deeper this pass; worth a quick diff between the
two if one is ever modified, to catch the other silently drifting out
of sync (same "parallel-file drift" risk already seen elsewhere in this
project, e.g. the driver/up login-interceptor finding on the EtelID
side).

---

## 4. Frontend — `admin.html`'s real scale

`admin.html` alone now loads **30 separate JS files** (was ~15 in the
original pass) - direct, measured evidence of how much the admin
surface has grown: `admin-equb.js`, `admin-equity.js`,
`admin-platform-economics.js`, `admin-danger-zone.js`,
`admin-business-plan.js`, `admin-payroll.js`, `admin-pdf-report.js`,
`admin-finance-hub.js`, `admin-etelpay.js`, `admin-support.js`,
`admin-migrations.js`, `admin-announcements.js`,
`admin-launch-checklist.js`, `admin-releases.js`, plus all the
originally-traced admin files. This is now firmly a full back-office
ERP, not just a delivery-ops dashboard - worth treating as its own
multi-session scope area going forward rather than "the admin panel"
as a single unit, the way §7 already suggests splitting it.

**Real JS→action reference counts** (same cross-check method as the
original pass - counting literal action-name string matches, not
assumed from file naming):

| JS file | Actions referenced |
|---|---|
| `driver-bundle.js` | 31 |
| `driver.js` | 31 |
| `customer.js` | 23 |
| `admin.js` | 23 |
| `admin-pricing.js` | 18 |
| `servicegiver-bundle.js` | 15 |
| `admin-equity.js` | 13 |
| `admin-equb.js` | 12 |
| `courier-bundle.js` | 12 |
| `admin-rides.js` | 11 |
| `ride-bundle.js` | 10 |
| `admin-business-plan.js` | 9 |
| `ride.js` | 6 |
| `admin-platform-economics.js` | 6 |
| `customer-bundle.js` | 4 |
| `admin-danger-zone.js` | 2 |

`driver-bundle.js`/`driver.js` and `customer.js` are the biggest single
JS-side action surfaces in the whole system now - consistent with the
"ride/driver core" and "customer/order flow" being the two heaviest-used
parts of the product day to day.

**New cross-cutting frontend file:** `investor-nav-check.js` is loaded
on both `customer.html` and `servicegiver.html` - a small script
checking investor-related nav state, shown even to non-admin,
non-investor users. Not traced deeper this pass; worth checking exactly
what it does and why it's on customer-facing pages specifically, since
that's a slightly unusual place for investor-relations code to live.

---

## 5. Not yet covered / not re-traced this pass

- **Line counts** for `RideController`, `AdminController`,
  `OrderController`, `ServiceGiverController`, `AuthController`,
  `PushController` weren't re-measured this pass (only their action
  counts were) - worth a quick `wc -l` pass next time for a complete
  size picture.
- `equb/index.html` and `investor/index.html` weren't traced for their
  own JS/CSS asset loading this pass (script tags didn't match the
  simple grep pattern used for the other 13 pages - likely a different
  quoting style or inline scripts).
- The five new controllers' internal `lib/` usage was checked
  (`grep`-confirmed for Equb; the other four showed no `lib/` requires
  found this pass) but not read line-by-line the way the older,
  larger controllers were in the original session.
- `equb_cron.php` (the scheduled job sharing Equb's processing logic)
  wasn't located/read this pass - flagged only because
  `EqubController.php`'s own comment mentions it exists.
- Dead-file hunting (the original report's §7) wasn't re-run this pass
  - worth doing given how much has grown since, on the theory that
    faster growth usually means faster accumulation of abandoned files
    too.

---

## 6. Bugs found and fixed this session (not part of the original trace)

Two real, live bugs were found and fixed while investigating a
reported "wrong splash icon" issue - documented here since they're now
part of this system's real history, not just findings:

1. **`customer.html` had no PWA manifest link at all.** "Add to Home
   Screen" on the customer app fell back to whatever the browser had
   cached from elsewhere in the same origin, which is why it was
   showing Service Giver's icon. Fixed: added the same static
   `<link rel="manifest">` pattern `driver/index.html` and
   `ride/index.html` already used correctly, pointing to the plain
   `manifest.json` (the genuine "ETEL Delivery" customer-facing brand).
2. **`login.html`'s role-detection switch had no `customer` branch.**
   Driver/Ride/Courier/Service/Admin were all explicitly handled (a
   comment in the file documents that Courier/Service/Admin themselves
   were a similar earlier fix); Customer silently fell into the generic
   fallback. Added an explicit `isCustomer` branch for consistency,
   though `customer.html`'s own static tag above is what actually
   matters in practice, since most customer traffic reaches that page
   directly rather than through `login.html`.

Separately: if "Driver" is still showing the wrong icon on a specific
phone after this, that's very likely a **stale, already-installed home
screen shortcut** predating an even earlier fix (the code itself
already correctly points `driver/index.html` at `manifest-driver.json`)
- Android doesn't retroactively refresh an installed PWA's icon just
because the manifest changed server-side; removing and re-adding the
shortcut is the fix for that specific case, not a code change.

---

## 7. Suggested session scoping (updated)

1. **Ride core** — `RideController.php` (67 actions) + ride frontend - still the single biggest backend surface, now more so than before
2. **Admin - core ops** — `AdminController.php` (84 actions) + the ~15 "original" admin-*.js files (pricing, rides, roles, sos, etc.)
3. **Admin - finance/ownership** — `EquityController.php`, `PlatformEconomicsController.php`, `PlanningController.php` + their JS - split out from #2 given how much this area alone has grown
4. **Digital Equb** — `EqubController.php` + `lib/equb_processing.php` + `lib/wallet_ops.php` + `admin-equb.js` - its own scope given real automatic money movement is involved
5. **Investor Portal** — `InvestorPortalController.php` + `investor/index.html` + `investor-nav-check.js` - small but a genuinely different auth model, worth not mixing into admin-scoped sessions
6. **System Reset** — `SystemResetController.php` on its own whenever touched, given the stated blast radius - treat with the same care as `lib/ride_fare.php`/`schema_helpers.php` changes, arguably more
7. **Delivery & order flow** — `OrderController.php` + `ServiceGiverController.php` + customer/courier/servicegiver frontend
8. **Fuel tracking** — `DriverFuelController.php` + `CourierFuelController.php` together, given their likely-parallel structure
9. **Shared lib/** — its own session whenever touching any of the 8 lib files; grep every caller first
10. **Standalone PHP utilities, Auth & Push** — unchanged scope from the original report
