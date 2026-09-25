---
name: Etel Gebeya
description: Ethiopia's escrow-protected multi-vendor marketplace
colors:
  gold: "#D4AF37"
  gold-bright: "#FFE066"
  gold-deep-dash: "#8B7230"
  gold-deep-store: "#B8902E"
  vault-navy: "#0A0A1A"
  vault-navy-surface: "#11112A"
  vault-card: "rgba(9,7,3,.88)"
  vault-border: "rgba(212,175,55,.22)"
  market-black: "#080604"
  market-black-2: "#0d0a06"
  market-card: "#160f06"
  market-card-2: "#1c140a"
  market-border: "rgba(212,175,55,.18)"
  text-dash: "#E8E8F0"
  text-dash-muted: "#8888A8"
  text-store: "#F7EFD9"
  text-store-muted: "#C9B88A"
  text-store-faint: "#96804F"
  info-blue-dash: "#60B4FF"
  info-blue-store: "#3b82f6"
  success: "#2ECC71"
  danger: "#E74C3C"
  orange: "#FF9500"
typography:
  display-lg-storefront:
    fontFamily: "Sora, sans-serif"
    fontWeight: 800
    letterSpacing: "-0.02em"
    fontSize: "42px"
  display-storefront:
    fontFamily: "Sora, sans-serif"
    fontWeight: 800
    letterSpacing: "-0.02em"
    fontSize: "24px"
  title-storefront:
    fontFamily: "Sora, sans-serif"
    fontWeight: 700
    fontSize: "17px"
  body-dashboard:
    fontFamily: "Outfit, 'Noto Sans Ethiopic', system-ui, sans-serif"
    fontWeight: 400
  body-storefront:
    fontFamily: "Sora, 'Noto Sans Ethiopic', system-ui, sans-serif"
    fontWeight: 400
    fontSize: "14px"
  label-storefront:
    fontFamily: "Sora, 'Noto Sans Ethiopic', system-ui, sans-serif"
    fontWeight: 700
    fontSize: "11px"
  caption-storefront:
    fontFamily: "Sora, 'Noto Sans Ethiopic', system-ui, sans-serif"
    fontWeight: 700
    fontSize: "9.5px"
  amharic:
    fontFamily: "'Noto Sans Ethiopic', sans-serif"
rounded:
  dashboard-sm: "12px"
  dashboard-md: "18px"
  dashboard-lg: "24px"
  storefront-sm: "10px"
  storefront-md: "14px"
components:
  button-primary-dashboard:
    backgroundColor: "linear-gradient(135deg, {colors.gold}, {colors.gold-deep-dash})"
    textColor: "#080604"
    rounded: "{rounded.dashboard-sm}"
    padding: "10px 21px"
  button-primary-storefront:
    backgroundColor: "{colors.gold}"
    textColor: "#080604"
    rounded: "{rounded.storefront-sm}"
    padding: "10px 22px"
---

# Design System: Etel Gebeya

## Overview

**Creative North Star: "The Guarded Treasury"**

Etel Gebeya is a vault, not a showroom. Gold is the thing being protected, not just the brand color — it shows up sparingly, on borders, on the one action that matters, on the badge that tells a seller their store is verified. Everything else recedes into near-black so the gold reads as valuable rather than decorative. This is a direct expression of the platform's actual mechanism: orders paid through the Etel Pay wallet sit in escrow until a customer confirms receipt, and the interface's restraint is the same restraint a vault has — nothing flashes, nothing over-explains, the gold only appears where trust is actually being extended or confirmed.

The system currently spans two related but distinct surfaces sharing the same identity: the **dashboards** (vendor panel, admin panel — cooler navy-black, `Sora` headings over `Outfit` body text) and the **storefront** (customer-facing shopping — warmer near-black, `Sora` used for both headings and body). Both are legitimate, both are incumbent; they are not meant to be unified into one background value, only kept internally consistent with each other.

**Key Characteristics:**
- Near-black backgrounds (two distinct warmths: navy for dashboards, warm black for storefront) with a single gold accent family
- Flat at rest; a gold glow or lift only appears in response to hover, focus, or an active state
- Bilingual by construction — every type role has a Noto Sans Ethiopic fallback, never bolted on after the fact
- Gold spent deliberately: primary actions, borders, status/verification signals — not fills or backgrounds

## Colors

The palette is a single gold family read against two near-black neutrals; nothing else competes with it for attention.

### Primary
- **Vault Gold** (`#D4AF37`): the one accent color. Primary buttons, active nav states, focus rings, section icons, verification badges. Used sparingly by design — its rarity is what makes it read as valuable.
- **Torch Gold** (`#FFE066`): the bright end of the gold gradient. Appears only inside gradients (buttons, avatar badges, hero highlights), never as a flat fill on its own.

### Neutral — Dashboard surfaces (vendor panel, admin panel)
- **Vault Gold Wash**: the actual page background for every dashboard screen — a fixed radial+linear gradient sweeping through `#2A1600 → #5A3A08 → #9A6818 → #C8922A → #D4AF37 → #C8922A → #9A6818 → #5A3A08 → #2A1600` (see `public/assets/css/admin.css`'s `html, body` rule), meant to read as torchlit vault walls behind the sidebar and cards. This was flagged in an earlier audit as contradicting an older draft of this doc (which called for a flat Vault Navy page background) — the gradient is the confirmed, intentional look; this doc was the one that was stale.
- **Vault Navy** (`#0A0A1A`): the mobile browser chrome color (`<meta name="theme-color">`) and a couple of small accent borders — not a large visible surface anywhere in the current layout.
- **Vault Navy Surface** (`#11112A`, at 90%/80% opacity): the sidebar and topbar background — the actual dark surface users see, floating over the gold wash behind it.
- **Vault Card** (`rgba(9,7,3,.88)`): the near-opaque card surface used for every panel — deliberately opaque so the page's own ambient glow doesn't bleed through into card content (a real bug this project hit once; keep cards at or above this opacity).
- **Vault Border** (`rgba(212,175,55,.22)`): the thin gold-tinted hairline around every dashboard card.
- **Dashboard Text** (`#E8E8F0`) / **Dashboard Muted Text** (`#8888A8`): body text and secondary/helper text on dashboard surfaces.

### Neutral — Storefront surfaces (customer-facing shopping)
- **Market Black** (`#080604`) / **Market Black Deep** (`#0d0a06`): page and section backgrounds on the storefront.
- **Market Card** (`#160f06`) / **Market Card Raised** (`#1c140a`): product/category card backgrounds; the raised tone marks hover/active state, not a separate component.
- **Market Border** (`rgba(212,175,55,.18)`): storefront's equivalent hairline, slightly softer than the dashboard's.
- **Storefront Text** (`#F7EFD9`) / **Storefront Muted Text** (`#C9B88A`) / **Storefront Faint Text** (`#96804F`): a three-step text hierarchy for body, secondary, and tertiary/placeholder text. Faint Text was corrected from an earlier `#7A6840` after an audit found it failed WCAG AA contrast (~3.5:1) against card backgrounds at the small sizes it's used at (stat labels, category tags, footer titles) — `#96804F` clears 4.5:1 in the same hue family.

### Functional
- **Success** (`#2ECC71`): active/verified/paid states (store status pill, order delivered, escrow released).
- **Danger** (`#E74C3C`): errors, destructive actions, flagged content.
- **Orange** (`#FF9500`): warning/pending states (draft store, pending payout).
- **Info Blue**: `#60B4FF` on dashboards, `#3b82f6` on the storefront — kept as two distinct values rather than forced to match; don't introduce a third blue.

### Named Rules
**The Rarity Rule.** Vault Gold (the flat accent, `#D4AF37`) is spent on the single primary action per view and on real trust signals (verification, escrow status) — never as a decorative fill on a UI element, never on more than one element competing for attention at once. The dashboard's own ambient Vault Gold Wash page background is the one deliberate exception (it's the room the UI sits in, not a UI element competing for attention), not a license to reach for gold anywhere else — status pills, chips, link text, and table headers should still default to the functional colors below or to Dashboard Muted Text.

**The Opaque Card Rule.** Card/panel surfaces stay at or above the documented card opacity (`rgba(9,7,3,.88)` dashboards, solid `#160f06` storefront). A more transparent card lets the page's own ambient background glow show through and read as a rendering bug, not a design choice — this has actually happened in this codebase once.

## Typography

**Display/Heading Font:** Sora (400–900), used for all headings and numeric/stat emphasis on both surfaces.
**Dashboard Body Font:** Outfit (400–900), with Noto Sans Ethiopic fallback.
**Storefront Body Font:** Sora, with Noto Sans Ethiopic fallback — the storefront does not load Outfit; do not introduce it there.

**Character:** Confident and geometric at display sizes (Sora's weight range does the heavy lifting), quieter and highly legible at body sizes.

### Hierarchy
- **Display large (storefront)** (Sora, 800, `-0.02em`, ~36–52px cluster, documented at 42px): the hero H1 and the rare oversized decorative number.
- **Display (storefront)** (Sora, 800, `-0.02em`, ~22–26px cluster, documented at 24px): section titles, stat values.
- **Title (storefront)** (Sora, 700, ~17–18px): card/promo headline text, vendor banner heading.
- **Body (dashboard)** (Outfit, 400–600, ~0.9rem/14–15px): form labels, table cells, helper text.
- **Body (storefront)** (Sora, 400, ~13–15px cluster, documented at 14px): product names, descriptions, nav labels.
- **Label (storefront)** (Sora, 700, ~11–13px cluster, documented at 11px, often uppercase with letter-spacing): section eyebrows, badges, stat labels — this is the single most-used size in the storefront system.
- **Caption (storefront)** (Sora, 700, ~8–10.5px cluster, documented at 9.5px): footnote-level tags (promo card tags, category counts).

### Named Rules
**The Organic Scale Rule.** The storefront's type sizes were tuned per component rather than snapped to a strict round-number scale (11px, 11.5px, and 12px all appear as distinct label sizes). Treat the six roles above as the documented cluster centers; a literal size within roughly ±1.5px of the nearest cluster is normal fine-tuning, not drift. A size far outside every cluster (e.g. a new 20px label) is the real signal to check.

## Layout

Both surfaces are sidebar + content layouts: a fixed-width left sidebar (dashboard) or a collapsible left sidebar (storefront, `--sidebar-w: 220px`, collapses to 0 on mobile) next to a scrolling main content area. Dashboards use a two-column content pattern for settings-style pages (main form column + a narrower sticky rail, ~320px) when there's a persistent status/action to keep visible. Spacing is not on a strict numeric scale across the codebase; keep new spacing visually consistent with the surrounding component rather than inventing a new increment.

## Elevation & Depth

Flat by default. Cards and panels sit at their documented background with a thin gold-tinted border and no shadow at rest. Depth is introduced only as a response to interaction: a hover lift (`translateY`), a soft gold-tinted glow (`box-shadow: 0 6px 18px -4px rgba(212,175,55,.5)` on dashboard stat cards; `--shadow-gold: 0 4px 24px rgba(212,175,55,.15)` on storefront cards), or a focus ring. Nothing is elevated just to look "premium" — elevation is a signal that something is interactive or active right now.

### Shadow Vocabulary
- **Ambient card shadow** (`0 16px 40px -16px rgba(0,0,0,.4), 0 0 0 1px rgba(212,175,55,.15)`): dashboard stat cards, used sparingly.
- **Gold hover glow** (`0 6px 18px -4px rgba(212,175,55,.5)`): dashboard interactive elements on hover/focus.
- **Storefront card shadow** (`0 4px 24px rgba(212,175,55,.15)`, base `0 4px 24px rgba(0,0,0,.4)`): product/category cards on hover.

### Named Rules
**The Flat-at-Rest Rule.** No shadow or glow appears on a static element. If it's glowing, the user just did something or is about to.

## Shapes

Dashboard corners run larger than storefront corners: `12px` / `18px` / `24px` (small controls / cards / large panels) versus the storefront's tighter `10px` / `14px`. Borders are always the thin gold-tinted hairline, never a solid neutral gray. No sharp corners anywhere in the system — even the tightest radius (10px) reads as deliberately soft, never boxy.

## Components

### Buttons
- **Shape:** dashboard `12px` radius; storefront `10px` radius.
- **Primary:** gold gradient (`linear-gradient(135deg, #D4AF37, #E8C76B)` on dashboards; flat `#D4AF37` on the storefront) with near-black (`#080604`/`#1A1A2E`) text — high-contrast gold-on-black, never gold text on a light surface.
- **Hover / Focus:** a confident lift (`translateY(-2px)`) plus a brightened gold-tinted shadow; the button should feel tactile and eager to be pressed, not a subtle fade.
- **Secondary / Ghost:** transparent background, gold border, gold text; same hover lift at lower intensity.

### Cards / Containers
- **Corner Style:** dashboard `18px`; storefront product/category cards `14px`.
- **Background:** Vault Card (dashboard) or Market Card (storefront) — always opaque per the Opaque Card Rule.
- **Shadow Strategy:** flat at rest; see Elevation & Depth.
- **Border:** 1–1.5px gold-tinted hairline, always present.
- **Internal Padding:** dashboard cards ~1.5rem; storefront product cards tighter (~10–20px) to fit a denser grid.

### Status Pills / Badges
- **Style:** small dot + label, color communicates state (green = active/verified, orange = pending/draft, red = flagged). Never color alone — always paired with a text label.

### Inputs / Fields
- **Style:** translucent dark fill (`rgba(255,255,255,.05)`), 1.5px border at low-opacity white, `10px` radius.
- **Focus:** border shifts to solid Vault Gold — no glow ring, the border color change is the entire signal.

### Navigation
- **Style:** icon + label rows in the sidebar; active state gets a gold-tinted background wash and gold icon/text color. Section labels are small, uppercase, muted, wide letter-spacing.

## Do's and Don'ts

### Do:
- **Do** keep Vault Gold rare — one primary action, plus real trust/status signals, per screen.
- **Do** keep card backgrounds at documented opacity (Vault Card `rgba(9,7,3,.88)` / Market Card solid `#160f06`) so the page's ambient background can't show through.
- **Do** pair every status color with an icon or text label, never color alone.
- **Do** give every typographic role a Noto Sans Ethiopic fallback — bilingual is a product commitment, not a nice-to-have.
- **Do** reserve shadow/glow for hover, focus, and active states — flat at rest.

### Don't:
- **Don't** introduce a third near-black surface tone. Dashboard sidebars/panels use Vault Navy Surface; the storefront uses Market Black. Pick the one matching the surface.
- **Don't** mix Outfit into the storefront or Sora-only body copy into dashboards — the two surfaces' body fonts are intentionally different.
- **Don't** use gold as a fill on a UI element (buttons aside), a chip, or a table/text treatment — the one exception is the dashboard's own page-level Vault Gold Wash background, not a general license to use gold as a surface color elsewhere.
- **Don't** add a drop shadow to a static, non-interactive card "to make it feel premium" — that's exactly the failure mode this system avoids.
