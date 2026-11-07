# Agents programme - go-live checklist

Do these in order. The programme stays **OFF** (Agents > Settings > Programme) until every box is ticked. While it is OFF nobody earns anything, agents see "The agent programme is not open yet", and agents cannot book rides for customers.

## 1. Money rules (Agents > Settings)
- [ ] First-trip bonus and repeat bonus are the amounts you really want to pay (test values removed).
- [ ] Hold days: Bronze 7, Silver and Gold as decided (suggested Silver 5, Gold 3).
- [ ] Daily cap, weekly cap, monthly budget and minimum payout are set (0 = no limit).
- [ ] Agent booking: switch on/off as wanted, bookings per agent per day, cash trips earn the bonus (yes/no), how many first booked customers are reviewed (10 is the default).

## 2. Texts (SMS)
- [ ] The SMSEthiopia key in `delivery/api/.env` (`SMSET_API_KEY`) and in the EtelID `wp-config.php` (`ETELID_SMSET_API_KEY`) belong to the **paid** campaign (no "Test Message from smsethiopia.com" line on the texts).
- [ ] A test text to a phone number that is NOT verified in the SMS dashboard arrives (Agents > Settings > Text messages > Send test).
- [ ] Sender name registered in the SMS dashboard (Sender IDs) so customers see ETEL instead of a number.
- [ ] Open Agents > Messages: choose the language customers get (English, Amharic or both), read and edit each message, and use "Send to test phone" on each one. English costs 1 SMS credit, Amharic or both usually 2; the screen shows the credits for every message.
- [ ] SMS balance is enough for the expected volume (new-customer text, password reset codes, verification codes).

## 3. Clean the test data
- [ ] Test agents, test referrals, test bonuses and test bookings are removed. The Admin "System reset" wipes agents, referrals, earnings, payouts, bookings and support tickets but KEEPS the settings and the audit log. It cannot be undone: take a backup first and only run it before launch, never after real agents have joined.
- [ ] Test rides and test customers made during testing are removed or ignored.

## 4. Check each screen once with real phones
- [ ] An agent can apply, you approve with the age check, the agent signs in to the portal and sees the code and QR.
- [ ] A new rider scans the code, takes a ride, the bonus appears as "On hold" and becomes payable after the hold days.
- [ ] An agent books a ride for a customer: the driver is called, the customer pays cash, a new customer gets the text, the ride and bonus show in "Rides I booked".
- [ ] A trip ended without moving is billed the base fare only; a trip ended partway is billed for the distance travelled.
- [ ] A payout is created, exported and marked paid once; the audit log shows each step.
- [ ] Help (round button) opens the support desk from the agent portal, and a ticket reaches Admin > Support desk.

## 5. Switch on
- [ ] Take a database backup.
- [ ] Agents > Settings > Programme switch ON, then Save. Watch Agents > Dashboard and the Fraud queue for the first week.
- [ ] To pause at any time: Programme switch OFF. Money already earned stays and can still be paid.
