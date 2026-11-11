# Etel platform visual audit (phone, light and dark)

Checked on a 390 x 844 phone viewport with a headless browser, in light and dark, for sideways scrolling, a light/dark switch,
and text that is hard to read (automatic contrast check). Only screens that open without a signed-in session could be checked
(login screens, public pages, and the Madeya, Etel Group pages which were also checked signed in during their own work).
Screens behind a login in Delivery, Ride, Pay, Gebeya and Taxi need a live account and were **not** audited.

| App / page | Sideways scroll | Light/dark switch | Hard-to-read text | Result |
|---|---|---|---|---|
| Delivery home | none | yes | none (the flagged cards are dark by design) | OK |
| Ride (rider) login | none | yes | none | OK |
| Driver login | none | yes | none | OK |
| Customer login | none | yes | none | OK |
| Courier login | none | yes | none | OK |
| Delivery login, admin login, service giver, tracking | none | yes | none | OK |
| EtelTaxi home, driver, admin | none | yes (own `etaxi_theme` setting, on purpose) | none | OK |
| Etel Pay login | **70 px sideways scroll (fixed)** | no | none | Fixed overflow; no light theme |
| Etel Pay wallet, send, top up | none | no | none | No light theme |
| Portfolio page | none | no | none | No light theme |
| Etel Madeya (driver, controller, admin) | none | yes | none | OK (checked in their own work) |
| Etel Group master page and admin | none | yes | none | OK (checked in their own work) |
| Etel Gebeya | not checked (needs PHP and a database) | has its own theme files | - | Not audited |

## Fixed in this pass
- **Etel Pay login:** a decorative glow was wider than a phone and made the page scroll sideways. It is now clipped.

## Left as is, and why
- **Etel Pay has no light theme.** Its look is dark glass cards floating on a gold page. A light version is a redesign of every
  wallet screen, and the signed-in screens could not be viewed here, so I did not build it blind.
- **Portfolio page** has no switch. It is a single dark-gold page; adding the same switch as the master page is small.
- **EtelTaxi** keeps its own theme setting so a driver's choice there never changes another Etel app (this is written in its code).

## Suggested next
1. Sign-in screens of Ride, Driver, Courier, Pay, Gebeya and Taxi checked on a real phone with a test account.
2. Light theme for Etel Pay (needs a design decision first).
3. The portfolio page switch.
