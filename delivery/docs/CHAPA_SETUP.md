# Online payments with Chapa (automatic payment confirmation)

Chapa is the Ethiopian payment gateway that gives your customers one checkout page for Telebirr,
CBE Birr, banks and cards. The Etel Delivery and Etel Ride code for it is already written. When it is
switched on, a paid order or ride is confirmed automatically within seconds: no admin has to check an
SMS or a bank statement. Until it is switched on, payments stay on the manual Payments tab.

What the code does for safety (so you can explain it to an auditor or a partner):
- The amount always comes from the order or ride on the server, never from the customer's phone.
- A payment counts only after Chapa's own verify call confirms: status success, currency ETB, and the exact amount.
- The webhook is checked with a signature, and a payment can be settled only once (a repeat does nothing).
- A new background job (`cron_chapa_reconcile.php`) settles payments whose webhook or return page was lost.

## 1. Chapa account (about 1 to 3 days for approval)

1. Create a merchant account at chapa.co and complete the business verification (trade licence, TIN, bank account).
2. Open the Chapa dashboard, Settings, API. Copy the **secret key**.
   - While testing it starts with `CHASECK_TEST-`. The live key starts with `CHASECK-`.
3. Check Chapa's current fees and payout schedule in the dashboard before you go live.

## 2. Put the key on the server

1. cPanel File Manager, `public_html/delivery/api/.env`, edit.
2. Set (use the TEST key first):
   ```
   CHAPA_SECRET_KEY=CHASECK_TEST-...your test key...
   ETEL_BASE_URL=https://ethelmarket.com/delivery
   ```
3. Save. Never put the key in chat, email, a screenshot, or the repo.

## 3. Webhook in the Chapa dashboard

1. Settings, Webhooks.
2. Webhook URL: `https://ethelmarket.com/delivery/api/chapa-callback.php`
3. Leave the separate webhook "secret hash" **empty** so Chapa signs with your secret key (that is what the code checks).
   If you do set one, tell me and I will change the code to match.
4. Enable the events for successful payments.

## 4. The reconcile job (cPanel Cron Jobs, every 5 minutes)

```
/usr/local/bin/php -q /home/ACCOUNT/public_html/delivery/api/cron_chapa_reconcile.php >/dev/null 2>&1
```

Replace `ACCOUNT` with your cPanel account name. Open the Ride admin (or the main admin) Rides tab, System health:
the line **Online payments (Chapa)** turns green after the job has run.

## 5. Test in test mode (no real money)

1. Sign in to the customer app, add an item to the cart, choose the Chapa / online payment button at checkout.
2. On the Chapa page use Chapa's test payment details (they are listed in the Chapa developer docs under "Test mode").
3. Check, in this order:
   - After paying you come back to the customer app and the order shows **paid**.
   - The order is offered to couriers (online-paid orders are held back until payment is confirmed).
   - System health shows Online payments (Chapa) with nothing stuck.
4. Try a payment you abandon (close the Chapa page without paying). It must stay unpaid and be marked failed or pending, never paid.
5. For a ride: finish a test ride, pay the fare with Chapa, check that the driver's earnings show the fare as a platform-collected (not cash) payment.

## 6. Go live

1. Replace the key in `.env` with the **live** secret key (`CHASECK-...`).
2. Do one real order of the smallest amount, pay it with your own Telebirr, confirm it settles, then refund it from the Chapa dashboard.
3. Tell admins and couriers: online-paid orders appear paid automatically; nothing to confirm by hand.

## 7. If something looks wrong

| You see | Check |
|---|---|
| "Chapa is not set up on this server yet" | `CHAPA_SECRET_KEY` is empty or still a placeholder in `api/.env` |
| Customer pays but the order stays unpaid | System health: is the reconcile cron running? Wait 5 minutes; if still unpaid, open the payment in the Chapa dashboard and compare the amount with the order total |
| Webhook shows 401 in the Chapa dashboard | You set a webhook secret hash: see section 3 |
| "Could not reach Chapa right now" | Server cannot reach api.chapa.co (hosting firewall) or the key is wrong; the server error log has the reason |
| Amount mismatch in the error log | The order total changed after the customer started paying; the payment is refused on purpose. Refund it in Chapa and let the customer pay again |

## 8. Things Chapa does not replace

- Cash on delivery stays as it is.
- Etel Pay wallet payments stay as they are.
- Direct Telebirr/bank screenshots still go to the manual Payments tab if you keep that option.
