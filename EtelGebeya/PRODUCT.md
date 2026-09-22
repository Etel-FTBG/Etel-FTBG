# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Three primary roles, each with their own panel in the app:
- **Customers** — Ethiopian shoppers browsing and buying from independent sellers on the storefront, in either Amharic or English.
- **Vendors (sellers)** — independent merchants who register a store, list products, manage a supplier catalog, fulfill orders, and configure a pickup location for couriers, through the vendor panel.
- **Couriers** — collect orders from a vendor's registered pickup location and deliver to the customer, using the same courier/driver network that operates Etel Group's Delivery and Ride apps.
- **Platform admins** — operate the marketplace itself (seller approval, disputes, platform-wide reporting) through the admin panel.

## Product Purpose

An Ethiopia-focused, multi-vendor online marketplace (Amazon/Alibaba-style storefront model) where independent sellers list and sell products directly to local shoppers. Success means sellers can get a store running and taking orders quickly, and customers can buy with confidence that a real courier will pick up and deliver, without either side having to arrange their own logistics.

## Positioning

EtelGebeya's mechanism is the combination of two things a generic marketplace clone (or a delivery-less local storefront) couldn't truthfully copy:
1. **Native integration with Etel Group's own courier/driver network** — order fulfillment runs through the same pickup/delivery infrastructure as Etel's Delivery and Ride apps, so vendors don't need to arrange their own shipping.
2. **Built specifically for the Ethiopian market** — bilingual Amharic/English throughout, local payment rails (see Capabilities), and Ethiopia-specific addressing rather than a global-platform template.

## Operating Context

- Shared cPanel hosting at `ethelmarket.com/EtelGebeya/` (no CI/CD — a GitHub merge does not deploy to the live server; files must be pushed manually), custom PHP MVC (`app/Controllers`, `app/Views`, `app/Core`), no third-party framework.
- Three panels: Vendor panel (dashboard, products, suppliers, supplier catalog, orders, payouts, settings), Admin panel, and the public customer storefront/checkout.
- Vendors set a pickup location via a Google Maps address picker (migrated from Leaflet/OpenStreetMap); couriers use this to know where to collect orders.
- Dark navy (#0A0A1A) + gold (#D4AF37) is the established brand identity across the vendor/admin dashboards (`admin.css`); the public storefront (`etel-v2.css`) has its own lighter Alibaba-style product-card visual language. Both are incumbent, not to be replaced without an explicit redesign request.

## Capabilities and Constraints

- Checkout supports multiple Ethiopian payment methods: Telebirr, CBE Birr, bank transfer (Chapa), cash-on-delivery, and the Etel Pay wallet.
- Orders paid via the Etel Pay wallet go through a per-(order, seller) **escrow hold**: the customer is debited immediately, the seller sees it as "Pending" until the customer confirms receipt (or the daily auto-release cron / admin dispute resolution releases it). Cash-on-delivery and manual Telebirr/CBE instructions never enter escrow.
- Vendor-side data actually persisted for store settings: store name (English + Amharic), description, phone, pickup address/lat/lng — confirmed against `VendorController::saveSettings()`. Do not design around fields the backend doesn't yet save (e.g. there is no store-logo upload today).

## Brand Commitments

- Name: Etel Gebeya (part of the Etel Group / Etel Family Technology and Business Group). "ኢE Pay" / Etel Pay is the group's shared wallet product, reused here for escrow-protected checkout.
- Bilingual Amharic/English is a binding product commitment, not an optional nicety — every user-facing surface needs both.

## Evidence on Hand

- Real, working code for all three panels and the payment/escrow flow described above — this is the authority for current behavior (no separate DESIGN.md yet).
- No confirmed real vendor/customer volume metrics, testimonials, or case studies on hand — future work must not fabricate these.

## Product Principles

1. Bilingual by default — Amharic and English are both first-class, not an afterthought bolted onto an English-first design.
2. Fulfillment is the platform's job, not the vendor's — design should reinforce that couriers (not vendors) handle delivery.
3. Trust is earned through visible payment protection (escrow, clear payment-method state) rather than generic marketplace polish.
4. Vendor-facing surfaces are early-stage tools for people getting a store running for the first time — favor clarity and quick setup over density built for power users with large catalogs.
5. Never design a field or affordance the backend doesn't actually persist — verify against the controller before adding it.

## Accessibility & Inclusion

No product-specific accessibility requirement has been established beyond standard web accessibility practice (contrast, keyboard nav, focus visibility) — apply the general standard until told otherwise.
