# How courier pay works on ETEL Delivery (and how it should grow)

Checked against the code and the live database on 13 Oct 2026. Industry comparisons are general knowledge, not live research.

## 1. What a customer pays

```
items subtotal            (what the shop charges)
+ delivery fee            (zone fare x tier multiplier, + zone surge if switched on)
+ service charge          (5% of the items subtotal)
- promo / points discount (takes money off the total, never off the courier's fee)
= order total
```

Real order ETD-AA39F4: items 340 + delivery 70 (Tabor, moto) + service charge 17 = **ETB 427**.

## 2. Who gets what today

| Money | Goes to |
|---|---|
| Items subtotal | The shop (service giver) |
| Delivery fee | **The courier, 100%** (`orders.delivery_fee`, shown as "You earn") |
| Service charge (5% of items) | ETEL |
| Promo / points discount | Paid by ETEL (the courier's fee is not reduced) |
| Tips | Not supported |

For cash orders the courier collects the order total from the customer. The Cash reconcile panel in Admin lists delivered cash orders and lets staff mark the money as received. How the shop is paid out of that cash is not enforced in code today.

## 3. How the delivery fee is chosen

1. **Destination zone only.** Admin > Pricing > zones holds a base fare per zone (active zones today: Referral 55, Addis Ketema 60, University 60, Haik Dar 70, Liqe Mender 70, Tabor 70, Tulu Dimtu 70). The pickup place is not used at all.
2. **Tier multiplier:** moto x1.00, car x1.12, express cargo x1.60.
3. **Zone surge:** a zone can have a surge multiplier (x1.5) switched on. The courier receives the surged fee.
4. **Zone not in the database or inactive:** falls back to the fee in `config-etb.php`. (This fallback was broken until the fix that ships with this note: it silently charged a flat ETB 50 instead. Order ETD-C3EC06 to Hawela Teklehaimanot paid 50 instead of the configured 75.)
5. **GPS pricing (used only by the ETEL AI order tool):** `15 + 8 x km`, minimum 30, measured from Hawassa's centre, not from the shop. The function it needed was in the wrong folder, so it would have crashed; fixed too.

Live numbers: 36 delivered orders, average delivery fee **ETB 54.9**, range 25 to 130 (the 0-fee rows are test orders).

## 4. What the courier pay ignores today

- How far the courier rides to the shop (pickup distance).
- How far the shop is from the customer. Two orders to Tabor pay the same 70 whether the shop is 1 km or 6 km away.
- Waiting at the shop for food.
- Night, rain or rush-hour conditions (delivery has no peak windows; rides do).
- Tips, bonuses, targets, a minimum per order, compensation when an order is cancelled after pickup.
- Fuel: the courier fuel log exists, but pay does not consider it.

## 5. How other platforms pay couriers (general knowledge)

| Platform | Courier pay is roughly |
|---|---|
| DoorDash | Base pay (distance + estimated time + how undesirable the order is) + peak/challenge promotions + 100% of tips |
| Uber Eats | Pickup distance + drop-off distance + time + surge/boost + tips |
| Glovo, Wolt, Bolt Food | Base fee + per-km by distance bands + waiting time after a few minutes at the shop + peak boosts and weekly targets |
| African platforms (e.g. Chowdeck, Jumia Food) | Per-order amount by distance band + bonuses; customers pay the delivery fee, the platform earns the restaurant commission (often 15 to 30%) and tops up courier incentives itself |

The common pattern: courier pay follows **distance and time**, customer price follows **simplicity**, and the platform earns from the shop commission and fees, then spends part of it on incentives.

## 6. Recommended model for ETEL (needs the owner's numbers)

```
courier pay = base                      ETB 25
            + per-km rate x km          ETB 7 x (rider to shop, capped at 3 km + shop to customer)
            + waiting pay               ETB 2 per minute after 5 minutes at the shop, max 15 minutes
            x night / peak multiplier   reuse the existing night_multiplier rule (1.3)
            + 100% of tips
            minimum per order           ETB 40
```

Keep the customer's fee simple (zone based). Admin sees the margin per order (customer fee minus courier pay) and ETEL funds any shortfall from the 5% service charge and shop commission.

Rollout in safe steps:
1. Done here: fix the broken fallback and GPS functions, add a CI test, write this explainer.
2. Show the courier a fee breakdown on every job card.
3. Store the courier pay on each order when it is assigned (a snapshot, so later rate changes never alter history).
4. Add tips, then weekly targets.

Owner decisions needed before step 3: base amount, per-km rate, minimum, who funds the difference when courier pay is higher than the customer fee.
