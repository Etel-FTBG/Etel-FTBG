# Etel Group shared design tokens

`etel-tokens.css` is the canonical source for the gold/carbon brand
palette and type system used across Etel Group's subsidiaries
(EtelGroup, EtelID, Etel Delivery & Ride, EtelGebeya, EtelAi,
Etel.Pay, EtelMadeya). Before this file existed, every subsystem
re-declared the same handful of colors under its own variable names,
each hand-copied from whichever file someone had open at the time -
correct today, one typo away from drifting tomorrow.

## Deploying it

This file has no build step and no dependencies - it's plain CSS
custom properties. Deploy it once, to the site's document root, at:

```
ethelmarket.com/design-system/etel-tokens.css
```

Every subsystem lives at its own path on that same host, so a
root-relative `<link>` reaches it from anywhere:

```html
<link rel="stylesheet" href="/design-system/etel-tokens.css">
```

Add that link **before** the subsystem's own stylesheet, so its own
rules can still override anything token-based if it ever needs to.

## Migrating an existing subsystem onto it

EtelGroup's own `css/style.css` is the reference example - see the
`@import` at the top of that file and the shrunk `:root` block right
after it that now only holds EtelGroup-local tokens (`--deep-brown`,
`--nav-h`), not the shared ones.

To migrate another subsystem:

1. Add the `<link>` (or an `@import` at the very top of its own CSS
   file - `@import` must be the first rule, before anything else).
2. Compare its local token declarations against `etel-tokens.css`.
   Where the *value* already matches (e.g. Etel.Pay's `--gold-pale:
   #F5E6A3` is the same color as this file's `--gold-light`), decide
   whether to rename local usages to the shared token name, or add a
   one-line local alias (`--gold-pale: var(--gold-light);`) so
   existing rules don't all need touching at once.
3. Remove the now-duplicated declaration from the local `:root`.
4. Keep anything genuinely subsystem-specific (extra shades, glow
   colors, layout variables) declared locally - this file is only for
   values that are real shared brand constants, not a dumping ground
   for every color a given subsystem happens to use.

This is intentionally an opt-in migration, one subsystem at a time,
not a single mass find-and-replace - each subsystem is a separate live
production app with its own deploy cycle, and a blind rename risks a
visual regression nobody notices until it's live. EtelGroup has been
migrated as the reference implementation; the rest can follow the
same steps whenever that subsystem is next touched.
