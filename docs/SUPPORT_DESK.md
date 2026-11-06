# Etel Support desk - one inbox for every Etel app

**Customers** open `https://ethelmarket.com/delivery/help/` (the Help page). They sign in with their EtelID, choose the app and topic, write the problem, and follow the conversation there. A round Help button on every Etel app opens it with the app already chosen: `/delivery/help/?app=pay` (optionally `&cat=payment&ref=<trip or order number>`).

**Staff** work it in Admin > Support & records > **Support desk**: queue with filters, SLA "late" badges, the conversation, canned replies (English + Amharic), internal notes (customers never see them), assign, priority, close, stats, settings. Roles: `support.view` (read) and `support.manage` (reply, settings); an unrestricted admin has both. Give them to a role in Admin > Roles > Support desk.

## What is where
| Part | Files |
|---|---|
| Database | `delivery/api/migrations/103_support_desk.sql` (`sup_tickets`, `sup_messages`, `sup_canned`, `sup_settings`) |
| API | `lib/sup_core.php`, `lib/sup_notify.php`, `controllers/SupportController.php` (`sup_*`), `controllers/SupportAdminController.php` (`admin_sup_*`) |
| Help page | `delivery/help/` |
| Admin tab | `delivery/js/admin-support-desk.js` |
| Help button | `delivery/js/etel-help-fab.js`, loaded by one `<script>` line in each app's pages |

Apps carrying the Help button: Delivery customer, Ride, Driver, Courier, Service Giver, Delivery sign-in and tracking pages, Agents (Support screen), EtelPay wallet, EtelGebeya, EtelTaxi, EtelMadeya, Etel Group, EtelID (portal, sign-in, apply, profile, reset), EtelAi. It is not shown on admin or tool pages.

## Telegram (off by default)
Nothing is sent to Telegram until the owner turns it on in Support desk > Settings. It reuses the bot token the password-reset code already uses (`TG_BOT_TOKEN`). Only a short notice is sent (request number and link), never the message text or a phone number. "Staff chat id" gets new-request alerts. "Tell customers on Telegram" only reaches customers who linked Telegram to EtelID.

## Privacy and safety
Customers see only their own requests. Staff names are never shown to customers (they see "Etel Support"). Limits stop spam (open requests per customer, new requests per hour, messages per minute). Safety requests are urgent and the form tells people in danger to call the police (991) first.

## Manual acceptance
1. phpMyAdmin: run `103_support_desk.sql` (10 settings and 1 canned reply the first time, nothing the second).
2. Open `/delivery/help/`, sign in, send a test request (screenshot).
3. Admin > Support desk: it appears at the top (screenshot); reply with the canned reply.
4. Reload the Help page: the reply and the unread dot show (screenshot).
5. Open any app (for example EtelPay) and check the round Help button (screenshot).
6. Settings: switch Telegram on only when you are ready; it refuses to switch on without a target.
