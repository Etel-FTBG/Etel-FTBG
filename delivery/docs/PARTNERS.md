# Partners: agents register couriers, drivers and shops

An approved field agent can register a courier, a driver or a shop (with its products). The owner approves. The agent is paid a fixed fee once.

## For the owner (Admin > Agents > Partners)

1. **Review queue.** New submissions wait under "Waiting". Each row shows who registered it and a risk pill (Low, Check, High).
2. **Open one.** You see the warnings, the agent's record, every detail, the ID / selfie / licence / logo photos, and for a shop the products with prices and a map link.
3. **Check it.** Tick the three checklist boxes (photos match, phone works, details look real). Call the person if you are unsure.
4. **Decide.**
   - **Approve**: creates the real account, pays the agent the fee, and texts the person to set a password with "Forgot password". If the text could not be sent (SMS not set up), open the partner and press "Send the sign-in text again", or tell them to use Forgot password with their phone number.
   - **Ask for changes**: write what to fix. The agent sees it and can resend.
   - **Reject**: write why. The agent is not paid.
5. **Agent quality** shows sent, approved, rejected and average risk per agent. A high rejection rate is a reason to talk to that agent.
6. **Fees and rules** (agents.manage): on/off switch, fee per type (courier 50, driver 50, shop 150 ETB by default), minimum shop products (5), daily limit per agent (10), days before the fee becomes payable (0).

Fees are normal agent earnings, so they appear in the agent's balance, in Agents > Payouts batches, and in the earnings list (kind `partner_fee`).

### Warnings the system raises
- The same phone number or ID number is in another submission.
- The partner's phone is the agent's own phone, or the agent's payout number.
- The agent has many rejected submissions, or sent many in one day.

A warning never blocks anything. It is a reason to look closer.

### Switching it off
Fees and rules > "Agents can register partners". Off means agents cannot save or send. Drafts and the review queue stay.

## For the agent (Agent app > Register a courier, driver or shop)

1. Choose the type and tap Start.
2. Fill each step. The draft is saved on every Next, so a lost connection loses nothing.
3. Photos: ID card, selfie, licence (driver), shop logo, product photos. Take them in good light.
4. Shop: stand at the shop and tap "Use my location". Add at least the minimum number of products, each with a name and a price (add a photo too).
5. On the last step the app lists anything still missing. When complete, tap Send to ETEL.
6. Watch the status: With ETEL, Changes needed (read the note, fix, resend), Approved (the fee is in your balance), Not approved.

Rules: the partner must be a real person with their own phone number. A phone number that already has an ETEL account cannot be registered again.

## Technical notes
- Tables: `fa_partners` (migration 107). Staged data only; nothing is a real account before approval.
- Code: `api/lib/fa_partners.php`, `api/controllers/FieldAgentPartnerController.php`, agent screens `agent/partners.js`, admin board `js/admin-agents-partners.js`.
- Actions: agent `fa_partner_list/get/save/submit/delete`; admin `admin_fa_partners_list/stats`, `admin_fa_partner_get/decide/resend_sms`.
- Tests: `php tests/partners/backend_partners.php`, browser `tests/partners/ui_partners.js` (agent) and `ui_admin_partners.js` (admin).
