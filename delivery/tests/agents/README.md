# Agents programme - tests

These are the scripts used to verify the Agents programme (Phases 1-4). They are **not** wired into CI (they need a database and a browser).

| File | What it checks |
|---|---|
| `backend_phase1_3.php` | 88 checks: settings validation, apply/approve, claim, risk, earnings job, holds, caps, clawback, payouts, audit chain, reports |
| `backend_phase4.php` | 53 checks: tiers, tier progress, insights, nudges + cooldown, near-cap and budget alerts, shared-identity risk, weekly digest, EtelAI hook stays off |
| `backend_booking.php` | 45 checks: agent-assisted booking: who may book, vehicle list and quote, own/payout number refused, new customer account + ride + automatic link, rode-before and already-linked customers (booked, no bonus), cash counts + review hold, device signals skipped, sequential phones, daily cap, bookings list |
| `ui_agent_booking.js` | Portal "Book a ride": vehicle tiles, fares, confirm sheet, success card, list, plain error messages, Amharic, 320 px, light and dark, contrast |
| `ui_agent_booking_map.js` | Booking screen with a stand-in Google Maps: real vehicle pictures, place search (both fields), pins, route distance, live driver markers (picture icons), tap/drag to set pickup, re-render keeps the map, booking carries the coordinates, no-map and no-key fallbacks, 320 px, light and dark |
| `ui_admin_insights.js` | Admin Agents > Insights tab, light/dark switch, Amharic, 390 px and 1360 px |
| `ui_portal_theme.js` | Portal light/dark, shared `theme` key, tap target, WCAG AA contrast in both themes, tier card, messages, 320 px |

## Run

1. MariaDB with `sql_mode` including `ANSI_QUOTES`; create database `fa_test`; load a copy of the `users` and `rides` tables from `api/migrate.php`'s schema, then `api/migrations/101_field_agents.sql` and `102_field_agents_insights.sql`.
2. Backend: `php delivery/tests/agents/backend_phase4.php` (prints `PASSED: n` / `FAILED: n`). The two backend scripts share one database and change a few settings; run them one after the other, not at the same time.
3. UI: serve `delivery/` under `/delivery/` with `php -S 127.0.0.1:8099 -t <parent of delivery>` (set `DB_*` and `TOKEN_SECRET` for the app), create signed tokens for an admin and an agent into `tok-admin.txt` / `tok-0911000001.txt` in `ETEL_TOKDIR`, then `node delivery/tests/agents/ui_admin_insights.js`. Set `ETEL_HOST` and `ETEL_SHOTS` (screenshot folder) if needed.
