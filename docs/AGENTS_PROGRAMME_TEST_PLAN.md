# Agents programme - test plan (Phases 1-4)

Scope: the Etel Ride "Agents" referral programme: agent portal (`/delivery/agent/`), admin tab (Admin > Agents), API (`fa_*`, `admin_fa_*`), nightly job, rider hook (`js/agent-ref.js`). The programme ships **OFF** (`program_enabled = 0`).

## 1. Automated checks (run before every release)

| Area | How | Expected |
|---|---|---|
| PHP syntax / ASCII / size | `php -l` on each file; `grep -P '[^\x00-\x7F]'`; each PHP file under 25 KB | no output, no non-ASCII bytes |
| JS syntax | `node --check` on each file | no output |
| Repo CI scripts | `python3 delivery/tests/ci/check_reset_coverage.py`, `check_xss_sinks.py` | pass |
| Backend, Phases 1-3 | `delivery/tests/agents/backend_phase1_3.php` | 88 passed, 0 failed |
| Backend, Phase 4 | `delivery/tests/agents/backend_phase4.php` | 53 passed, 0 failed |
| Migrations idempotent | run `101` and `102` twice | second run changes nothing, no error |
| Admin UI | `ui_admin_insights.js` | Insights tab, theme switch, Amharic, 390 and 1360 px |
| Portal UI | `ui_portal_theme.js` and the Phase 3 portal suite | light and dark, shared `theme` key, AA contrast, 48 px targets, 320 px |
| Performance | Lighthouse mobile, throttled Fast 3G first screen | Performance 90+ (98 measured), Accessibility 100, Best practices 100; page under 150 KB excluding fonts (94 KB raw, about 27 KB gzipped) |

## 2. Rules that must hold (each has a test)

1. Self-referral is always blocked (same account, Etel ID, phone, or payout number).
2. Single level only: recruiting an agent earns nothing.
3. Bonus 1 once, after a NEW rider's first completed and paid trip. Bonus 2 only after 3 trips inside the window.
4. Minimum distance and time, daily and weekly caps (held, not dropped), hold period, clawback on refund or cancellation.
5. Risk is explainable: every hold shows plain reasons; nothing is auto-rejected or auto-paid without a person.
6. A tier never raises a bonus or a cap. Its only perks are a shorter hold (never under 3 days) and a badge.
7. The agent never sees a risk score, fraud word, or the tier limits for risk and rejection.
8. Nudges are in-portal only, at most one per agent per cooldown. Telegram and SMS are not connected.
9. EtelAI summary is a design hook only: always disabled; the payload is totals only (no names, phones, codes, devices, venues).
10. The audit log is append-only and hash-chained; an edited row is detected.
11. Light and dark both meet WCAG AA text contrast; the switch uses the shared `theme` key, so the choice follows the person to Ride, Driver and Admin.
12. Agent-booked customers: a booking never earns a bonus unless the customer is new; device-based fraud signals are replaced by the daily cap, the admin review of the first bookings and the sequential-phone check; cash trips count only when `agent_booking_cash_counts` is on.
13. The Agent app icon is managed from Admin > Branding > App icons > Agent.

## 3. Manual acceptance on the live site (after upload)

Do these in order; each has a screenshot to keep.

1. **Migration** - phpMyAdmin: run `102_field_agents_insights.sql`. Expect "13 rows inserted" first time, 0 the second time. *(screenshot: phpMyAdmin result)*
2. **Admin > Agents > Insights** - tab opens, no red banner; tier counts shown; "Messages go only inside the agent portal" note visible. *(screenshot: Insights tab, light)*
3. **Theme switch** - press the Light/Dark button in the Agents tab; the whole admin changes; reload keeps it. *(screenshot: Insights tab, dark)*
4. **Settings** - the Tiers numbers are editable; Gold smaller than Silver is refused with a plain message.
5. **Nudge** - tick a dormant agent, Send nudge; the agent's portal shows the message; a second try within the cooldown skips that agent. *(screenshot: agent portal Messages)*
6. **Portal** - open `/delivery/agent/` on a phone: the sun/moon switch changes the colours; Home shows "Next level" with a progress bar and no risk words. *(screenshots: Home in light and dark)*
7. **Branding** - Admin > Branding > App icons shows an Agent tile; replace it with a test image, reload the portal, check the tab icon, then restore. *(screenshot: Branding tile)*
8. **Digest** - Insights > Weekly digest > Build this week now; text shows in English and (with the language switch) Amharic. *(screenshot: digest)*
9. **Cron** - run `api/cron_field_agents.php` once from the browser with the cron key; the JSON reply includes `tiers_changed` and `digest_id`.

10. **Sign-in page (Phase 5)** - open `/delivery/agent/` signed out, on a phone: master logo with the gold ring, "Welcome, Agent", icon fields with the show-password eye, gold Sign in button, Create EtelID link, three trust tiles; works in Amharic, light and dark. Sign in with a real agent account. *(screenshots: sign-in dark and light)*
11. **Master logo everywhere** - the portal header, the badge picture and the printed A6 card show the master logo; the phone home-screen icon after reinstalling the app.

12. **Agent books a ride for a customer (Phase 6)** - as an approved agent open the portal > Home > "Book a ride for a customer". Enter a test customer phone, choose a vehicle, a distance, confirm. Check: the success card, the ride in Admin > Rides, the customer in Admin > Users, the booking in Admin > Agents > (agent) > "Rides booked for customers". *(screenshots: the form with fares, the success card, the admin ride)*
13. **Bonus for a booked customer** - complete that ride as a driver, run the earnings job (Admin > Agents > Dashboard > Run now): the bonus appears in the Fraud queue as "Held for admin review: this agent booked the ride..."; release it; after the 7-day hold it becomes payable. *(screenshot: the held bonus)*
14. **Limits** - Admin > Agents > Settings > "Agent booking": daily cap, cash switch, review count. Try a booking with the agent's own phone (refused) and one above the cap (refused in plain words).

## 4. Rollback

The programme switch (`program_enabled`) stops all earning at once. Files can be restored from the previous zip; migration `102` only adds one table and settings rows, nothing is removed or altered.

## 5. Known limits (intentional)

- Amharic wording needs a native-speaker review before launch.
- Support contacts (`t.me/etelsupport`, `support@ethelmarket.com`) are placeholders to confirm.
- Refund behaviour (assumption A2) needs the owner's confirmation.
