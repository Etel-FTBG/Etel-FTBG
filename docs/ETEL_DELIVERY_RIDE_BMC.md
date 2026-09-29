# Business Model Canvas — ETEL Delivery & Ride (Hawassa)

Draft based on the codebase (`delivery/`, `PRODUCT.md`). Items marked **[confirm]** are assumptions to verify. No financial figures are included on purpose; add your real numbers.

## 1. Customer Segments
- **Consumers in Hawassa** ordering food, groceries and goods (customer app).
- **Riders/passengers** hailing rides (ride app, open-meter and fixed fares).
- **Service givers (merchants):** restaurants, shops, pharmacies listing inventory (`servicegiver.html`).
- **Couriers and ride drivers** as the supply side (two-sided marketplace).
- **Equb/community groups** (`delivery/equb`) **[confirm]**.
- **Investors** (`delivery/investor`, cap-table view).

## 2. Value Propositions
- **Customers:** one app for delivery and rides, in English and Amharic. Pay with Telebirr, bank transfer or EtelPay wallet, and track orders live (`track.html`).
- **Merchants:** a ready customer base and courier fleet, an inventory dashboard, and payouts through EtelPay.
- **Drivers/couriers:** steady income, a driver app, and wallet settlement.
- **Everyone:** one EtelID login across Delivery, Ride, Taxi, Gebeya and Pay. It works as a PWA, offline-capable, on low-cost Android phones.

## 3. Channels
- PWA installed from the web (`install.html`), with no app-store dependency.
- Web at ethelmarket.com; push and Telegram notifications.
- Driver and merchant onboarding by the Etel Group team and agents (AgentController) **[confirm]**.
- Cross-promotion inside EtelGebeya, EtelTaxi and EtelPay.

## 4. Customer Relationships
- Self-service through the app; live order and ride tracking.
- In-app chat (`chat.php`) and support.
- Ratings, promotions and wallet balance as retention **[confirm]**.
- Personal onboarding for merchants and drivers.

## 5. Revenue Streams
- **5% commission** on merchant orders (commission logic is in `OrderController` and `PlatformEconomicsController`).
- **Delivery fee** by Hawassa zone (`$HAWASSA_DELIVERY_FEES` in `config-etb.php`).
- **Ride fares:** platform cut of metered or fixed fares.
- **Wallet float and fees** through EtelPay **[confirm]**.
- **Merchant subscriptions, ads or featured listings** **[confirm: not seen in code]**.

## 6. Key Resources
- Platform code: PHP 8, MySQL, PWA.
- EtelID (SSO) and EtelPay (wallet and escrow).
- Driver and courier network; merchant partnerships.
- Etel Group brand (gold/carbon identity) and local Hawassa knowledge.
- Telebirr and bank integrations.

## 7. Key Activities
- Order dispatch and ride matching.
- Payment settlement, escrow and payouts.
- Recruiting and onboarding drivers and merchants.
- Platform maintenance: migrations, security audits (INSA), updaters.
- Marketing and customer support.

## 8. Key Partners
- Telebirr and banks.
- Restaurants, shops and pharmacies.
- Independent drivers and couriers.
- Cloud, SMS and push providers; Telegram.
- Hawassa city and transport authorities; INSA for security compliance.
- Investors.

## 9. Cost Structure
- Driver and courier incentives or subsidies **[confirm]**.
- Payment gateway and transaction fees.
- Shared cPanel hosting, domain, SMS and push.
- Staff: support, agents, developers.
- Marketing and customer acquisition.
- Security audits and compliance.
- Insurance and dispute losses (refunds, failed deliveries).

## Risks and next steps
1. **Marketplace liquidity:** keep enough drivers and merchants in one city to give fast pickups.
2. **Unit economics:** get real commission, fare and cost-per-order data before fundraising.
3. **Shared hosting** limits scale and reliability; plan a migration path.
4. **Payment and regulatory exposure** for holding wallet balances in Ethiopia.
5. **Competition** from other Ethiopian ride and delivery apps.
