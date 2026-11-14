---
name: etel-investors-memory
description: Project memory for the Etel investor-relations work (EtelGroup/investors) and the Delivery & Ride launch plan (commission holiday, bonuses, referral rewards), built Nov 2026. Use at the start of ANY chat about investors, the pitch deck or PowerPoint, the deal sheet, profit shares, stages, the launch period or launch budget, the unsold-share rule, the agreement clause, commission plan, driver incentives, demo data, or when the user says continue / what did we build / remember. Read together with etel-group-ops (hosting and ASCII rules) and etel-delivery-ride-memory (Delivery & Ride background).
---

# Etel investors and launch plan: project memory (as of 2026-11-14)

Written from a long working session. It records what was built, what was decided, and what is still open, so a new chat does not have to ask again. Check `git log` on main for anything newer.

## 0. Who and how we work
- Owner: Dagi (use they/them). Etel Family Technology and Business Group, Hawassa. Trial phase, no real customer money yet.
- Delivery: ONE draft PR per change from branch `claude/admiring-fermi-8pcqlx` (create it from main, push, open a DRAFT PR, subscribe to PR activity). Dagi marks it ready and merges.
- After each merge: `git fetch origin main && git checkout -B claude/admiring-fermi-8pcqlx origin/main && git push -f -u origin claude/admiring-fermi-8pcqlx`, then zip ONLY the changed runtime files (keep folder paths like `EtelGroup/investors/...` and `delivery/...`) plus a short `TEST.txt` into the scratchpad as `ETEL_mergeNNN.zip`, send it with SendUserFile (display attach), and give a short note: which folders to upload, old files to delete, what to check. Never include `includes/config.php` or `api/.env`.
- Hosting is shared cPanel, no SSH: Dagi uploads zips by hand. PHP is written ASCII-only (the host WAF corrupted non-ASCII before) and each file stays under about 30KB.
- Dagi prefers short answers and finished files; says "yes" to proposals. Never put personal details (email) in outside requests or committed files.

## 1. What was built (merged PRs)
| PR | What |
|---|---|
| #763-#766 | Investor module (`EtelGroup/investors`): staged raise (3 stages), deal sheet (EN/AM, signature, print), pitch page, demo/real switcher, launch period + launch budget, order income, planner scenarios |
| #767 | Delivery commission plan: new drivers 0% for 60 days, 2% for 30 days, then 5% (pricing_rules `commission_plan`); rate stored per ride (`rides.commission_rate`, `commission_amt`) |
| #768 | Delivery launch incentives (`ride_incentives.php`): monthly budget cap, daily trip bonus tiers, driver referral reward, anti-farming rules, Void; auto-counted in the investor launch budget |
| #769 | Demo data also fills sample cost lines, an active launch period and ETB 92,500 of sample launch spending (`api/lib/DemoLaunch.php`); Clear demo removes them |
| #770 | One launch-end rule read everywhere (admin, pitch, deal sheet, portal, demo): ends at most 90 days after start, or earlier at a 4-week average of 150 trips a day |
| #771 | The unsold-share rule is explained on the deal sheet, the declaration and the pitch; draft agreement clause written |

Everything else (the presentations, presenter guide) is under `EtelGroup/investors/docs/` (see section 5).

## 2. Key decisions (do not re-litigate)
- Investors share ONLY Delivery & Ride for now; other Etel companies are separate and not part of the deal. Delivery & Ride first.
- Staged raise (profit-share rights, NOT company ownership): Stage 1 400 shares at ETB 1,000 = ETB 400,000 (open now); Stage 2 600 at ETB 1,500 = ETB 900,000 (opens at 400 trips a day); Stage 3 1,000 at ETB 2,000 = ETB 2,000,000 (opens at 1,000 trips a day). Pool = 2,000 shares = 20% of net profit. Keep the 400k Stage 1.
- Payout rule: pool = 20% of net profit (zero if no profit; investors never absorb losses). Each investor gets shares/2,000 of the pool, times 2.5 until they have received back what they paid, then times 1.
- UNSOLD-SHARE BONUS (kept on purpose): shares not yet sold would earn the boosted rate; half of that amount is shared among existing holders pro rata, half stays with the Company; it ends at 2,000 sold. With only Stage 1 sold, a 100-share holder earns ETB 7,500 per ETB 100,000 profit instead of 2,500. Draft clause: `EtelGroup/investors/docs/AGREEMENT_CLAUSE_UNSOLD_SHARES.md`. The calculator is unchanged (`DistributionEngine.php`).
- Launch plan: commission-free period for new drivers, driver bonuses, referral rewards, rider discounts. NO investor payouts during the launch (locking a distribution is refused while the launch is on). Growth spending is paid from the raise (the launch budget), NOT deducted from the profit that is shared.
- Launch budget split of the Stage 1 raise: Reserve 26%, Marketing 25%, Promotion 15%, Bonuses 12%, Referral 10%, Materials 12% (proposed, to be confirmed).
- Demo mode: demo data is clearly labelled; the deal form returns a DEMO preview and stores nothing. Dagi will remove the demo once real operation starts.
- Do not show the 5% commission in marketing or office material (investors may see it).

## 3. Money facts (Dagi's real costs; assumptions marked)
- Real: office rent ETB 6,000 a month; customer service about ETB 10,000 a month; ETB 20,000 a year (treated as a yearly fixed cost). "Today's costs" = 17,667 a month.
- Assumed (replace with real): average fare ETB 120 (Etel earns 5% = ETB 6 a trip); full team ETB 150,000 a month. Break-even about 99 trips a day on today's costs, about 834 with a full team. Illustration: 100 shares earn about ETB 3,000 a month at 1,500 trips a day with a full team, roughly 33 months to pay back before the unsold-share bonus. Always present these as illustrations, never a promise or a payback date.
- Demo cost lines also include assumed hosting/maps/SMS, gateway fees, licence and taxes, and 10% contingency.

## 4. Where things live
- Investor module: `EtelGroup/investors/` (prefix router in `api/boot.php`: `deal_`/`admin_deal_` to DealController; `admin_launch*`, `investor_launch` to LaunchController; `setup_` to SetupController; `admin_` to AdminController; `investor_` to PortalController). Settings in `ir_settings` via `ir_get_setting/ir_set_setting`. Tables: `ir_tranches`, `ir_launch_budget`, `ir_launch_spend`. Launch settings keys: `launch_active`, `launch_started_on`, `launch_max_days` (90), `launch_trips_goal` (150), `launch_until`, `launch_note`, `launch_auto_from`, `include_orders`, `order_commission_rate`, `demo_mode`.
- Key files: `api/lib/Launch.php` (rule, state, budget summary), `api/lib/DeliveryReadonly.php` (read-only Delivery data incl. incentive totals), `api/lib/DemoLaunch.php`, `js/deal-sheet.js` (EN/AM text), `js/pitch-r3.js` (pitch slides, EN/AM), `README.md`.
- Delivery: `delivery/api/lib/ride_commission.php` (plan, rate per driver and day), `ride_incentives.php`, `ride_fare.php` (hooks), `AdminPricingController.php` (commission and incentive plan actions), `js/admin-pricing-studio.js` (Commission and Incentives tabs), `docs/COMMISSION_PLAN.md`.
- Investor read-only database user needs SELECT on Delivery `orders` if order income is switched on.

## 5. Documents and decks (all under `EtelGroup/investors/docs/`)
- `ETEL_Investor_Presenter_Guide.pdf`: 5-page guide (story, payout math, honest numbers, talk track, Q&A). Written before the unsold-share decision text was final; the logic is still right.
- `AGREEMENT_CLAUSE_UNSOLD_SHARES.md`: draft clause for the lawyer.
- `presentation/`: English and Amharic PowerPoint decks (14 slides, speaker notes) and the build sources; see its README. Amharic deck needs the free Noto Sans Ethiopic font.

## 6. Testing
- Investor smoke tests (need a fresh DB each run): from `EtelGroup`, set `DB_HOST=localhost DB_NAME=etelgroup_test DB_USER=egt DB_PASS=egtpass ADMIN_TOKEN_SECRET=testsecret INV_TOKEN_SECRET=x INV_CRYPTO_KEY=x`, run `php -S 127.0.0.1:8781 -t .`, then `python3 investors/tests/launch_smoke.py` and `deal_sheet_smoke.py` (recreate `etelgroup_test` before each). Local MariaDB stops between turns: `mysqld_safe --user=root --bind-address=127.0.0.1`.
- Delivery: `delivery/tests/ci/run_integration.sh` (fresh migrated DB) and the lint scripts `tests/ci/check_*.py`; registration is rate-limited per IP so smokes use the seeded demo accounts. New tables must be added to the reset wipe list (`SystemResetController.php`).
- Not verifiable in the sandbox: real EtelID sign-in, live Delivery data, Telegram or email delivery, real traction numbers.

## 7. Open items (checklist)
1. Lawyer reviews the Profit-Share Investment Agreement, the unsold-share clause, and the launch clause ("no payouts during launch; at most 90 days or 150 trips a day").
2. Native Amharic review of new Amharic text (deal sheet and pitch additions, the Amharic PowerPoint).
3. Replace assumed numbers with real ones: average fare, full-team cost, traction; enter real costs in Admin > Costs and stages (rent, customer service, yearly 20,000/12, hosting/maps/SMS, gateway fees, licence/taxes, insurance, about 10% contingency).
4. Set the investor commission rate to the real rate while the 5% is off for new drivers; give the read-only DB user SELECT on `orders` if order income is used.
5. Regenerate the blank PDFs in `EtelGroup/investors/forms` (still show the old declaration and no unsold-share point); signed sheets already stored keep their old text.
6. Announce driver bonuses in writing when the incentive plan is switched on (the app does not notify); start with a small monthly budget.
7. Add contact details (phone) to slide 14 of both decks.
8. Madeya items still open from earlier sessions: Telegram tokens and chat ids, station region/lat/lng, tank sizes.
9. Confirm assumptions: ETB 20,000 is a yearly fixed cost; "material" means branding/uniforms/stickers/printed items; "reference" means referral rewards.
