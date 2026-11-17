# Etel Code: live checklist (run by hand, about 20 minutes)

Use a test phone and two test accounts. Replace `E1616` with the code you claim. A code is NOT case sensitive and spaces or dashes are ignored
(`e1616`, `E-16 16` all work).

## 0. Get a code (once)
1. EtelID portal > **Etel Code** tile > type `E1616` > enter your EtelID password > Claim. It says "Your Etel Code is E1616".
2. Try a second account claiming `E1616`: it must say **Taken**.
3. Try `E12` (too short), `A1616` (does not start with E), `ETEL` (reserved), `E912345678` (looks like a phone): each is refused with a message.
4. Try changing your own code the same day: it says you can change again on a date 30 days later.

## 1. Sign in with phone, then with code (each must work both ways)
| App | Phone + password | Code + password | Wrong code |
|---|---|---|---|
| EtelID portal | works | works | "No account found with this phone number or Etel Code" |
| Delivery customer app | works | works | "Incorrect phone number or password" |
| Ride app | works | works | same message |
| Driver app | works | works | same message |
| Courier app | works | works | same message |
| Service giver (shop) app | works | works | same message |
| "Sign in with EtelID" button (any app) | works | works | refused |
| EtelPay login | works | works | refused |
| The ETEL app (phone) | works | works | refused |
The wrong-code message must be the SAME as for a wrong password (it must not say whether the code exists).

## 2. Send money by code (EtelPay)
1. Send money > type the receiver's **code**. It shows the owner's **name** (and no phone number) before you pay.
2. A code of a person without an EtelPay wallet: "This person has no active Etel Pay wallet yet".
3. A wrong code: "Etel Code not found".
4. Type 21 different codes in one minute: the 21st says "Too many look-ups".
5. Pay 1 ETB to the code. The receiver's wallet gets it. Check the receipt shows the right name.

## 3. Agent booking by code (Agent app > Book a ride)
1. Customer phone box: type the customer's **code**. The booking is made for that customer.
2. A wrong code: "Etel Code not found. Check it, or use the phone number."
3. A phone number still works as before.
4. Sign out, then try the same from a customer account (not an agent): it must NOT say "Etel Code not found"; it is simply refused.

## 4. Driver Street Hail
Driver app > Street Hail > type the customer's code instead of the phone number: the customer's name shows and the ride links to them.

## 5. Admin phone boxes (Admin, signed in with two-step)
Phone booking, Equb add member, Equb grant access, link investor, EtelPay override: each accepts a code and uses that person's phone.
These must NOT accept a code: sign-up forms, profile phone, payout phone, emergency contact (they keep exactly what you type).

## 6. Things that must be true
- Two people never hold the same code.
- After you change your code, nobody else can take your old one for 90 days (you can take it back).
- A code that was released does not sign anyone in.
- Suspended or revoked accounts cannot sign in by code.

Write down any step that does not match and send it with a screenshot.
