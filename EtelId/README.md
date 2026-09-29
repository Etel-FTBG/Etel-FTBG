# Etel-ID Premium
### WordPress Plugin v2.0.0 · ኢትኤል ቡድን

Official biometric-grade corporate identity system for **Etel Group**.
Issues premium 3D ID cards linked across all 8 companies — inspired by
the Ethiopian Fayda Digital ID (ፋይዳ) and US-standard government IDs.

---

## Features

| Feature | Detail |
|---------|--------|
| 3D card design | CSS 3D flip, holographic strip, UV watermark, biometric chip |
| Unique IDs | Etel-ID, FIN, 16-digit card number, serial number per member |
| QR code | Links to public verification page at `/etel-id/verify/{ID}` |
| Ethiopian calendar | All dates shown in both Gregorian and Ethiopian calendar |
| 8 companies | Members linkable to all Etel Group subsidiaries |
| 5 tiers | Standard, Staff, Manager, Executive, VIP |
| Audit log | Every event (issued, verified, suspended, printed) tracked |
| Print-ready | CR80 exact (85.6 × 53.98mm), crop marks, 2mm bleed, A4 sheet |
| Dark admin | Premium dark-gold fintech dashboard |
| 4-step form | Multi-step public application with live validation |
| PHP 5.6+ | No arrow functions — compatible with shared/InfinityFree hosting |

---

## Installation

1. Upload the `etel-id-premium` folder to `/wp-content/plugins/`
2. Go to **Plugins → Installed Plugins** and click **Activate**
3. Database tables are created automatically on activation
4. Visit **Etel-ID** in your WP admin sidebar
5. Go to **Settings → Flush Rewrite Rules** if verify/print URLs return 404

---

## Admin Pages

| Page | Purpose |
|------|---------|
| **Members** | Search, filter, manage all issued IDs |
| **Issue New ID** | Create a new corporate ID manually |
| **View & Print** | Preview card + open print page |
| **Audit Log** | Full event history for any member |
| **Settings** | Org config, shortcodes reference, system info |

---

## Shortcodes

```
[etelid_apply]
```
Full 4-step application form (personal → address → company → review).
AJAX submit, rate-limited, sends welcome email.

```
[etelid_verify]
```
Inline ID lookup. Accepts Etel-ID, FIN, or 16-digit card number.

```
[etelid_card id="EG-2025-00001"]
[etelid_card id="EG-2025-00001" flip="0" print="1"]
```
Renders a specific member's 3D flippable card anywhere on your site.

```
[etelid_stats]
```
Live stats widget — total issued, active, suspended, companies.

```
[etelid_my_id]
```
Shows the currently logged-in WordPress user's own Etel-ID card,
matched by their WP account email address.

---

## Public URLs

| URL | Purpose |
|-----|---------|
| `/etel-id/verify/{ETEL-ID}` | QR code landing — shows full card + details |
| `/etel-id/print/{ETEL-ID}` | Print page — A4 sheet, both sides, crop marks |
| `/etel-id/apply/` | Standalone apply page |

---

## ID Number Formats

| Field | Format | Example |
|-------|--------|---------|
| Etel-ID | `{PREFIX}-{YEAR}-{NNNNN}` | `EG-2025-00001` |
| FIN | `XXXX-XXXX-XXXX` | `3580-1754-1932` |
| Card Number | `XXXX XXXX XXXX XXXX` | `6408 3195 8602 8154` |
| Serial | `NNNNNNN` | `6271288` |

---

## Card Design Reference

**Front face:**
- Dark carbon top bar with animated holographic rainbow strip
- ኢE brand signature + "ETEL GROUP · CORPORATE ID" + tier badge
- Left side date strip (vertical, issue/expiry dates)
- Photo zone with biometric corner brackets
- Gold EMV chip with contact pad grid
- Full name (Latin + Amharic), DOB (Gregorian + Ethiopian), sex, blood type
- Company, role, expiry
- 16-digit card number + Code128 barcode
- Etel-ID watermark number
- Microprint security strip at bottom

**Back face:**
- UV security watermark pattern
- Company chip badges (all linked companies)
- Magnetic stripe
- OVD holographic stripe
- Signature panel with repeating pattern
- Phone, nationality, region/zone, role
- FIN number
- Large QR code (links to verify URL)
- Serial number
- Lost-and-found notice in Amharic + English

---

## Database Tables

```sql
{prefix}etelid_members   -- 35+ columns, all identity + status fields
{prefix}etelid_companies -- member ↔ company links (many-to-many)
{prefix}etelid_log       -- audit trail with IP + user agent
```

---

## Companies Registry

| Company | Code | Status |
|---------|------|--------|
| Etel Group | EG | Active |
| Etel Madeya | EM | Active |
| Etel Market | MK | Active |
| Etel Delivery | DL | Active |
| Etel Transport | TR | Planned |
| Etel Laundry | LN | Active |
| Etel Pay | EP | Planned |
| Etel Media | MD | Planned |
| LuxLine | LX | Planned |

---

## Print Instructions

1. Admin → **View & Print** → search for a member → **Open Print Page**
2. Browser print dialog: **Margins = None**, **Background graphics = ON**, **Paper = A4**
3. The page prints front side then back side, each at exact CR80 dimensions
4. Crop marks indicate where to cut after printing

---

## PHP 5.6 Compatibility

This plugin is fully compatible with PHP 5.6+:
- No arrow functions (`fn() =>`)
- No named arguments
- No `match` expressions
- No nullsafe operator (`?->`)
- Standard `foreach` instead of spread operators
- All `use` closures explicit

Tested on InfinityFree (PHP 5.6 / 7.4 shared hosting).

---

## Build Log

| Step | Files | Description |
|------|-------|-------------|
| 1 | 10 | Plugin core, DB schema (3 tables), generator, QR, security, mailer, AJAX, rewrites |
| 2 | 5  | 3D card CSS, front/back/view templates, frontend JS with tilt parallax |
| 3 | 9  | Dark-gold admin CSS, dashboard, issue, print, log, settings views + admin JS |
| 4 | 5  | Public pages CSS, 4-step apply form, verify page, verify-form shortcode |
| 5 | 3  | Print CSS (CR80 exact), print-page template, extended rewrite rules |
| 6 | 3  | Full shortcodes (5), complete settings view, README |

---

*Etel Group · ኢትኤል ቡድን · etelgroup.com*

## Limited (Ride-only) accounts

Quick Register (`templates/apply-form.php`) now asks only for full name, phone and an optional email. An account created that way has `profile_limited = 1` and `kyc_level = 0`: it can use **Ride** and nothing else in Etel Group.

- The limit lifts automatically (`EtelID_DB::refresh_limited()`, called after `profile_update` and `fayda_id_submit`) once the member has a profile photo **and** both sides of their Fayda ID on file. That sets `profile_limited = 0` and `kyc_level = 1`. The second, reviewed pass (Etel Pay) is unchanged.
- Existing members are never limited: the column defaults to 0 and only the new Quick Register sets it to 1.
- Service API: `member` now carries `kyc_level`, `profile_limited`, `allowed_apps` and `missing_items`. `POST /service/authenticate` accepts an optional `app` (e.g. `gebeya`, `pay`, `taxi`, `madeya`, `group`) and refuses a limited account for anything except `ride` (403 `profile_incomplete`). `/service/kyc` sets `ride_allowed` for limited accounts and every other `*_allowed` flag to false.
- `etel-api.php` (standalone gateway): `login` and `verify_token` honour `project` the same way.
- Delivery/Ride does not pass `app` (Ride lives in the same codebase as Delivery); it stores the flag on `users.etelid_limited` and refuses limited users in the order, service-giver, equb and courier controllers. See `delivery/api/etelid_bridge.php` and `boot.php` (`get_auth_user`).
