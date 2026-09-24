# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Etel Group is a multi-service company in Hawassa, Ethiopia, running several linked apps (delivery & ride-hailing, an online marketplace, taxi dispatch, and its own identity provider). EtelPay is the shared digital wallet used across all of them. Its login page (`Etel.Pay/wallet/login.html`) is opened by two distinct groups on the same screen:
- **Wallet holders** — everyday customers who use EtelPay to pay for orders/rides, send/receive money, and check their balance.
- **Merchants and vendors** — business accounts (e.g. sellers on the EtelGebeya marketplace) who sign in through the same page to manage payouts and business balance.

Both groups use the same phone+PIN or single-sign-on EtelID credentials; there is no separate business login surface.

## Product Purpose

EtelPay is the financial backbone connecting every Etel Group service under one wallet. Instead of each app (delivery, ride-hailing, marketplace) handling its own payments independently, EtelPay centralizes balance, transaction history, escrow holds, and payouts, and EtelID (Etel Group's SSO) lets one identity move across all of them without re-authenticating per app.

## Positioning

Not a standalone payment app — the shared settlement layer beneath an entire group of consumer and merchant-facing services, unified by one login identity (EtelID) that a single-purpose wallet competitor could not replicate without also owning the delivery, ride, and marketplace apps it settles for.

## Operating Context

- Used on mobile web (Android-majority, per observed user devices) inside a PWA-style wrapper, not a native app store listing.
- Sits alongside sibling Etel Group login surfaces (e.g. "ETEL Driver") that already share a premium gold/carbon visual identity — EtelPay's login should read as part of the same family, not a separate product.
- Money-moving surface: PIN entry and balance-adjacent screens carry real payment stakes, not just informational content.

## Capabilities and Constraints

- Existing login page supports: phone + PIN sign-in (Enter-key submits; no dedicated submit button, per explicit product decision), EtelID single-sign-on button (the primary/recommended path), a "Create Wallet" registration tab, a "Forgot PIN" recovery flow, and an English/Amharic language toggle. All of this functionality must be preserved exactly — this is a visual/UX redesign, not a functional change.
- Backend PHP files in this codebase have a hard ASCII-only constraint (this host's WAF has previously corrupted non-ASCII bytes in PHP source); this does not apply to the HTML/CSS/JS of the login page itself, but no new content should assume this login page can safely call new backend endpoints without checking that constraint first.
- No confirmed hard performance/device constraint was raised for this redesign — richer visual effects and animation are acceptable (explicitly confirmed over a lightweight-only approach).
- Undecided: exact legal/regulatory disclosure requirements (if any) for a financial login screen in Ethiopia were not raised and should not be assumed.

## Brand Commitments

- Gold/carbon "premium" visual identity already established across Etel Group's apps: dark carbon backgrounds (`#030205`/`#0d0d0d`/`#0a0703`/`#130d05`), a diagonal gold-bronze page backdrop gradient, gold accents (`#C8960C`, `#D4AF37`, `#F2CC6B`), ornate gold-bordered "coin/ID card" treatments with a holographic edge strip, Syne (headings), Cormorant Garamond (display/serif accents), and JetBrains Mono (labels/data) typography.
- The sibling "ETEL Driver" login page (dark background, ornate gold coin badge behind the card, rich ornamental framing) is the explicit aspirational reference for how premium this should feel.
- Product name/mark: "ኢE Pay" / "ETEL PAY", Etel Group, Hawassa, Ethiopia.

## Evidence on Hand

- Live screenshots of the current EtelPay login page (plain cream background, flat white card, functional but not premium) and the sibling ETEL Driver login page (ornate gold coin-badge design) were provided directly by the product owner and are the working before/after reference for this redesign.
- No user research, testimonials, or usage metrics are on hand; do not fabricate any.

## Product Principles

1. One identity, many services — the wallet's design should reinforce that signing in here means access across the whole Etel Group, not just a single app.
2. Real money, real trust — visual polish must never come at the cost of clarity for a screen where a wrong tap has financial consequences.
3. Family resemblance over independence — EtelPay's surfaces should look like siblings of Delivery, Ride, and Driver, not a separate product with its own visual language.
4. Serve both audiences on one screen — merchants and everyday customers share this exact login; nothing here should visually or functionally imply "consumer-only."

## Accessibility & Inclusion

No product-specific accessibility requirement was established beyond general good practice (legible contrast, tappable target sizes) — carry forward the contrast fixes already made to this page in prior work rather than reintroducing low-contrast text.
