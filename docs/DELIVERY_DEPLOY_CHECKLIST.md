# ETEL Delivery — Deploy Checklist

Use after merging PRs 333–355 (and any later delivery change). Tick top to bottom; each part says
what you should see. If a step fails, stop and note the step number — it points straight at the cause.

## 0. How releases work (read once)
- **Driver, Ride and Courier** pages load their JavaScript through the release loader: the version
  they use comes from **Admin → Releases** (stable / canary). Changes to those bundles go live only when
  you flip the version there.
- **Customer, Service and Admin** pages load their files directly. Uploading the files is the release.
  There is no canary and no version switch for them; rolling back means re-uploading the previous files.
- **Server (API) changes** apply to every app and every version immediately.

## 1. Upload
- [ ] Upload the changed `delivery/` files (or pull `main` on the server).
- [ ] Keep `delivery/api/config` / `.env` untouched.

## 2. Database — run migrations (web `api/migrate.php` or CLI)
New since the first checklist:

| Migration | What it adds |
|---|---|
| 060 `user_display_prefs` | per-user display size |
| 061 `users_etelid_limited` | "limited" EtelID accounts |
| 062 `sg_cover_url` | shop cover photo |
| 063 `promo_codes` | promo codes + seeds `PREMIUM50`, `FREEDEL` |
| 064 `loyalty_points` | points ledger, `orders.points_used/points_discount/discount` |
| 065 `review_replies_tags` | review tags + shop replies, and backfills each shop's rating |
| 066 `order_chat` | order chat tables |
| 067 `menu_catalog` | shared menu catalog (+ about 67 starter dishes), items.catalog_id / quality / portion |

- [ ] All seven show as applied. (The code also creates its tables/columns on first use, so a missed
      migration usually fixes itself — but run them anyway so the admin tools show everything.)

## 3. Driver / Ride / Courier releases (Admin → Releases)
- [ ] Driver: **stable → v15**
- [ ] Ride: **stable → v7**
- [ ] Courier: **stable → v3** (newest folder in `js/releases/courier`)
- [ ] Open each app once and confirm it loads.

## 4. Clear caches
- [ ] Hard-refresh the customer, service and admin pages once (service worker cache name is in `delivery/sw.js`).
- [ ] If a phone still shows old screens: close the app completely and reopen.

## 5. Smoke test — in this order (about 20 minutes, use two phones or two browsers)
Use a test customer, a test shop (Service app) and a test courier. Admin can change an order's status
step by step if you don't want to walk it through with real devices.

### 5.1 Login & basics
- [ ] Admin login works with the phone typed as `09…` and as `+2519…`.
- [ ] Customer app: home loads; the 🌙/☀️ toggle switches light and dark everywhere (home, shop page, cart, profile, tracking).
- [ ] Shop cards show logo/cover, Open/Closed and delivery fee; the shop page has sticky tabs and filter chips (All · Popular · Deals · Quick).
- [ ] Service app → Settings: upload logo + cover; the Online switch works.
- [ ] Admin → Users: "📞 Duplicate phones" loads. Admin → "Test as… → Service" creates only "Admin test shop (not live)".

### 5.2 Promo codes
- [ ] Admin → **🏷️ Promo codes** lists `PREMIUM50` (50%, max ETB 150, first order) and `FREEDEL` (free delivery, items ≥ ETB 500). **Edit PREMIUM50's cap if you want a different limit.**
- [ ] Customer: add items → cart → type `PREMIUM50` → total drops, green discount line. A wrong code shows a clear message.
- [ ] Place the order. Check the order total equals what the cart showed. In Admin → Promo codes, "Used" is 1 and "Given" shows the amount.

### 5.3 Order notifications (needs VAPID keys — Admin → Web Push Self-Test)
- [ ] Customer app: tap Allow on notifications (or "🔔 Turn on order alerts" on the home order card).
- [ ] Walk an order through: shop "preparing" → courier assigned → picked up → delivered. Each step sends one notification; the delivered one says how many points you earned and opens the rating sheet.

### 5.3b Courier id fix
- [ ] With a courier online, place an order. It appears in that courier's **active orders** (not only in Admin).

### 5.4 Loyalty points
- [ ] After the order is **delivered**, Profile → Points shows points (1 per ETB 10 of items; double on Saturday/Sunday).
- [ ] You need 100 points to spend. At checkout a "⭐ Use my points" switch appears; turning it on lowers the total (10 points = ETB 1).
- [ ] Cancel an order that used points (Admin → set status cancelled): the points come back.

### 5.5 Order again
- [ ] My Orders → a delivered order → **🔁 Order again** fills the cart at today's prices (items no longer available are skipped and counted).

### 5.6 Ratings & reviews
- [ ] Customer: rate a delivered order (overall stars, optional quality/courier stars, tags, comment).
- [ ] My Orders now shows "★★★★☆ Your rating" for it; the shop's card shows its star rating.
- [ ] Service app → **⭐ Reviews**: the review is there with a red badge; send a reply.
- [ ] Customer: the shop page → Reviews tab shows the tags and "Reply from the shop".
- [ ] Courier stars show on the courier's profile and next to the courier on the tracking screen.

### 5.7 Order chat
- [ ] Customer → tracking screen → **💬 Chat with the shop & courier**; send a message.
- [ ] The shop and the courier get a notification and a 💬 button with a red count; the message appears in their app within a few seconds. Reply from each.
- [ ] After the order has been delivered for more than 2 hours the chat is read-only.

### 5.8 Shop reports
- [ ] Service app → **Analytics**: Today / 7 days / 30 days; orders and sales match the Orders list; busy hours and top items show.

### 5.9 APK button
- [ ] In a normal browser (not the installed app) open the Profile of Customer / Driver / Ride / Courier / Service: **📲 Download the ETEL app** is there and downloads the APK. No pop-up banner anywhere.

### 5.10 Admin: platform pulse and order chats
- [ ] Admin → Dashboard → **📊 Platform pulse**: Today / 7 / 30 days. Orders and sales match what you know; the "Needs attention" counts link to the right pages.
- [ ] Admin → **💬 Order chats**: orders with messages are listed; "⏳ Customer waiting" shows chats where the customer wrote last more than 10 minutes ago. Open one and reply — it shows as **ETEL Support** to the customer, shop and courier.

### 5.11 Menu catalog
- [ ] Run migration 067 (Admin → Migrations). Admin → **📚 Menu catalog** lists about 67 starter dishes; edit, add, hide as you like.
- [ ] Service app → My Services → **📚 Pick from catalog**: tick dishes, type prices, **Add**. They appear in the item list with the shop's own price. Dishes already on the menu show "✓ On your menu".
- [ ] "Quick start" chips (e.g. ethiopian restaurant) + **Tick all shown** select a whole starter menu; shops then only type prices.
- [ ] Add custom item → tick "Suggest this dish for the shared catalog" → it shows under Admin → Menu catalog → ⏳ Suggested → **Approve**.
- [ ] My Services → **⬇ CSV template**, fill it (name, price, optional quality / portion / category / Amharic name), then **⬆ Import CSV**: dishes that match the catalog by name are linked to it, the rest become custom items (and are suggested to Admin), already-listed dishes are skipped, bad rows are reported by row number.
- [ ] Customer app: items marked Special / Premium show a small label, and the portion appears next to the shop name.

## 6. Tell shop owners
- [ ] Use the **Online** switch when open; closed shops show as Closed to customers.
- [ ] Reply to reviews in the new Reviews page; keep chat messages short and polite.

## Rollback
- Customer / Service / Admin files: re-upload the previous files (every PR is a clean revert of its merge commit).
- Driver / Ride / Courier bundles: flip stable back to the previous version in Admin → Releases.
- Migrations 060–067 only add tables/columns and are safe to leave in place.
- Known limit: orders auto-assigned to a courier before the courier-id fix (PR 354) still hold the old id; only new orders are corrected.
