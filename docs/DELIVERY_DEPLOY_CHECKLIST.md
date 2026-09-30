# ETEL Delivery — Deploy Checklist

Use after merging PRs 333–345 (and any later delivery change).

## 1. Upload
- [ ] Upload the changed `delivery/` files (or pull `main` on the server).
- [ ] Keep `delivery/api/config` / `.env` untouched.

## 2. Database
- [ ] Run migrations (web `api/migrate.php` or CLI). New since last deploy: `060_user_display_prefs`, `061_users_etelid_limited`, `062_sg_cover_url`.
- [ ] Shop logo/cover columns are also auto-added at runtime if migration 062 is missed.

## 3. Driver & Ride app releases (Admin → Releases panel)
- [ ] Driver: set **stable** → `v15`.
- [ ] Ride: set **stable** → `v7`.
- [ ] Open each app once to confirm it loads the new version.

## 4. Clear caches
- [ ] Hard-refresh customer / service / admin pages (service worker is `etel-v73`; old tabs update on next load).
- [ ] If a page looks old on a phone: close the app fully and reopen once.

## 5. Smoke test (5 minutes)
- [ ] Login: admin phone in both `09…` and `+2519…` forms.
- [ ] Customer: home loads, theme toggle works in light and dark, open a shop, add to cart, open cart and checkout.
- [ ] Shop cards show logo/cover, Open/Closed badge, delivery fee.
- [ ] Service app: upload logo + cover, flip the **Online** switch.
- [ ] Admin → Service Givers: "📞 Duplicate phones" report loads; no unexpected placeholder shops.
- [ ] Admin → "Test as… → Service" creates only an **"Admin test shop (not live)"** (status pending, hidden from customers).

## 6. Tell shop owners
- [ ] Use the **Online** switch when open; closed shops show as Closed to customers.

## Rollback
Revert the merge commit and redeploy; migrations 060–062 only add columns and are safe to leave in place.
