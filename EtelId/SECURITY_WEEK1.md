# EtelID - Week 1 security hardening

What changed, and the few things only YOU can do on the server.
Nothing here changes how the card, login or portal look.

## What changed in the code

| # | Problem found | Fix |
|---|---|---|
| 1 | `/print/{Etel-ID}` showed the full card (DOB, phone, FIN, card number...) to anyone; IDs are sequential | Only the card's owner (EtelID session) or an admin can open it. Others are sent to /login/ |
| 1b | Public `/verify/{id}` page (QR) printed the whole identity | Still public (that is its job) but sensitive fields are masked: DOB shows the year only, phone/FIN/card number show the last digits, blood type and email hidden. Lookups limited to 60 per IP per 15 min (page) / 30 (AJAX search) |
| 2 | One master `ETELID_SERVICE_KEY` could do everything for every app | Optional **per-app keys with scopes** (and optional IP lock) - see below. The master key keeps working so nothing breaks. Every call is logged with the app name. Guessing keys is throttled |
| 3 | Rate limits trusted `X-Forwarded-For` (forgeable) and the NFC step was limited per IP+card, not per account | Client IP is trusted from a proxy header **only** when the connection really comes from Cloudflare (or an IP in `ETELID_TRUSTED_PROXIES`). NFC and SSO password checks lock an **account** after 8 wrong passwords for 15 min (correct passwords never count against you) |
| 5 | Old/lost NFC cards stayed valid forever (21 active cards for 4 people) | Issuing a new card revokes the member's earlier cards (tick "keep old" to add a second physical card on purpose). New `etelid_nfc_revoke_member` action = lost-card button |
| 6 | Resetting a password did not log other devices out; ~47 live sessions per person | Password change/reset (self-service, portal, admin) ends the other sessions. Each person keeps at most 15 sessions |
| 7 | Code-patching endpoint secret could be guessed without limit; a stray EtelPay `updater-api.php` sat in the plugin | Secret guesses locked per IP (8 misses / 15 min). `updater-api.php` removed from the repo |
| - | Admin password reset allowed 6 chars; emailed temp password used `md5(uniqid())` | 8-char minimum; temp password from a secure random source; admin reset also ends sessions |

## Do these on the server (cPanel, ~15 minutes)

1. **Deploy** the zip over the EtelId plugin folder. (Then in File Manager **delete `EtelId/updater-api.php`** if it exists - a zip cannot delete it.)
2. **Per-app keys (recommended).** Edit `wp-config.php`, above `/* That's all, stop editing! */`:
   ```php
   define('ETELID_SERVICE_KEYS', '[
     {"app":"delivery","key":"<NEW random 40+ chars>","scopes":["resolve","authenticate","check_role","company_admin"]},
     {"app":"etelpay","key":"<NEW random 40+ chars>","scopes":["resolve","authenticate"]},
     {"app":"etelgroup","key":"<NEW random 40+ chars>","scopes":["list_company_roles","set_company_role","remove_company_role","member_count","list_companies"]}
   ]');
   ```
   Put each app's own key in that app's `.env` (`ETELID_SERVICE_KEY=`) and test it. Add `"ips":["<server ip>"]` to lock a key to its server. When every app uses its own key, add `define('ETELID_DISABLE_MASTER_KEY', true);` and the old master key stops working.
   Scopes available: resolve, authenticate, company_admin, check_role, list_company_roles, set_company_role, remove_company_role, member_count, list_companies. Only EtelGroup admin needs `set_company_role`.
3. **Are you behind Cloudflare?** If yes, nothing to do. If you use another proxy/CDN, add its IPs: `define('ETELID_TRUSTED_PROXIES', 'x.x.x.x,y.y.y.y/24');`. If neither, nothing to do either (the real connection address is used).
4. **Clean up the old NFC cards** (phpMyAdmin, EtelID database). Review first - this revokes every active card except each member's newest, so make sure nobody is relying on an older physical card:
   ```sql
   UPDATE Etelid_etelid_nfc_cards c
   JOIN (SELECT member_id, MAX(id) keep_id FROM Etelid_etelid_nfc_cards
         WHERE status='active' GROUP BY member_id) k ON k.member_id = c.member_id
   SET c.status='revoked' WHERE c.status='active' AND c.id <> k.keep_id;
   ```
5. **Strong secrets**: make sure `ETELID_UPDATER_SECRET` (if you use it) is 32+ random characters.
6. **Verify**: open `/print/EG-2026-00001` in a private window - you should be sent to the login page. Open `/verify/EG-2026-00001` - FIN and card number should show as `****`.

## Not done this week (deliberately)

* The icon font on the login/portal pages still loads from a CDN (`@latest`). It will be replaced by a local icon set in the design week (so the icons match the new look) instead of pinning a guessed version now.
* NFC chips are still static URL tags (cloneable). Next phase: NTAG 424 DNA tags with a signed code per tap.
* Existing sessions stay valid until their owner changes password or signs in again (then old ones are trimmed to 15).

## Tests

`php EtelId/tests/security_unit.php` (also runs in GitHub CI): spoof-proof IP, lockouts, masked view, per-app key scopes, session revocation and NFC auto-revoke.
