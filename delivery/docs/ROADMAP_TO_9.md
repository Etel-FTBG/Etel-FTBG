# Etel Delivery - honest rating and roadmap to 9/10

Written 2026-10-12 from the code, the live system health, and read-only queries on the live database.
Not measured: real load, real courier behaviour, customer-screen visuals (the customer app needs a signed-in account to render).
Scored as a single-city launch product against Uber Eats, DoorDash, Wolt, Glovo, Talabat and Jumia Food.

## What the live data says
- 46 orders in total, 11 customers, 12 shops, 40 items, 10 courier accounts (only 2 have ever done real deliveries; the rest are staff or test accounts). This is a pre-launch system.
- 2 of 40 items have a photo; 1 of 12 shops has a logo or cover. A food app with no pictures does not look premium, whatever the colours.
- 2 orders handed to couriers at 11:47 and 11:54 on 2026-10-12 were still "assigned" 4+ hours later: nobody accepted, nothing noticed them. Job offers have never been configured (`courier_offer_settings` is empty).
- 15 delivered orders (about ETB 5,700) are still marked unpaid, including 9 cash orders: delivering a cash order never recorded the payment.
- 1 order paid online through Chapa (ETB 1,110) was then cancelled. There is no refund step.
- Delivered-order timestamps are unreliable (average "placed to delivered" came out at 17 days), so delivery time cannot be measured yet.
- Site responds in about 87 ms (health check).

## Scores (now -> target)
| Area | Now | Target | Main gap |
|---|---|---|---|
| Features | 7 | 8.5 | smart dispatch, ETA, batching, loyalty |
| Payments | 5.5 | 8.5 | Chapa not live for customers; cash settlement; refunds |
| Visual look | 6 | 8.5 | no photos, dense screens, theme fixed screen by screen |
| Usability | 6 | 8.5 | five-tab login, many taps |
| Performance | 6 | 8 | shared hosting, large JS files, never load-tested |
| Stability | 5.5 | 8.5 | stuck orders unnoticed, no staging, one shared dispatcher |
| Security | 7 | 8.5 | admin 2FA not switched on, CSP inline scripts, no pen test |
| Operations | 5.5 | 8.5 | uptime alerts unfinished, no off-server backup, no status page |
| Brand and local fit | 7.5 | 8.5 | local advantage is real |
| **Overall** | **6.2** | **8.5** | |

9 overall needs things code cannot supply: real couriers and shops with photos, better hosting, an independent penetration test, and months of real orders. 8.5 is the honest target for this roadmap; the last half point comes from running it.

## Phases
**Phase 0 - fix what is live now (done in this batch)**
- Health page flags orders stuck with a courier over 30 minutes, delivered orders with no payment record, and paid-then-cancelled orders.
- Payments page: "Delivered orders not marked paid" list with a logged "mark received" action; Chapa list flags paid-but-cancelled orders for refund.

**Phase 1 - look and feel (biggest first impression)**
- Customer home: large photo cards, one search bar, sticky cart bar; clean placeholder art when a shop or item has no photo.
- Admin tool to add shop logos/covers and item photos in bulk; target 100% of live items with a photo.
- One set of colour variables for light and dark, with the contrast check run on every app.
- Separate entry per role instead of five tabs.

**Phase 2 - dispatch and courier**
- Switch job offers on (45-60 s, 3 tries); release and re-offer orders that sit assigned without progress.
- Courier home: big online switch, earnings card, daily goal, accept screen with countdown.
- Record real timestamps (accepted, picked up, delivered) so delivery time and courier speed can be measured.

**Phase 3 - payments**
- Chapa live for customers; refund step for cancelled paid orders.
- Courier cash ledger: cash collected, handed in, outstanding per courier.
- Receipts and a daily finance summary.

**Phase 4 - reliability**
- Staging copy; run the existing smoke tests and a load test before each release.
- Off-server daily backups; uptime alerts to phone; public status page.
- Split Delivery out of the shared dispatcher in steps.

**Phase 5 - security**
- Admin 2FA on for everyone; CSP without inline scripts (move handlers to files, use nonces).
- Independent penetration test; fix findings.
