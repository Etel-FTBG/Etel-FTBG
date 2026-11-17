# Two-step sign-in for admins and staff: required, and how to get back in

## The rule (Delivery and Ride)
- Every admin and staff account (staff are role "admin" with a staff role) must have two-step sign-in on to use the admin pages.
- Until it is on, the account can still sign in, open the **Two-step sign-in** menu item, and open the System health page. Every other admin action answers "Two-step sign-in is required" and the admin page opens the set-up screen by itself.
- The rule is ON by default. An owner can switch it off in Admin > Rides > System health (that saves `require_admin_2fa = 0`). Health then shows a warning.
- Admin > System health lists every admin or staff account that still has no two-step ("Admin and staff two-step").

## First day (owner)
1. Upload the files, open Admin. The two-step set-up opens. Scan the QR code with an authenticator app, type the 6-digit code, and **save the 8 backup codes** somewhere safe (paper, a second phone). They are shown once.
2. Ask every other admin and staff member to do the same.
3. Check Admin > Rides > System health: "Admin and staff two-step" should say every account has it on.

## Lost phone: ways back in (try in this order)
1. **A backup code.** Type one at the sign-in code prompt (single use).
2. **Another admin resets the account.** Admin > Security > the person > Reset two-step sign-in (`admin_reset_user_2fa`). They sign in with the password and set it up again.
3. **phpMyAdmin one line** (cPanel > phpMyAdmin > the Delivery database > SQL):
   `UPDATE admin_2fa SET enabled = 0 WHERE user_id = 1;`  (use the person's user id; the owner is usually 1)
4. **Emergency switch for the requirement** (when the only admin cannot even set it up): cPanel > File Manager > `delivery/api/.env` > add a line `ADMIN_2FA_REQUIRED=0`. Sign in, fix the account, then **delete that line**. System health shows a red "SWITCHED OFF" row while it is there. (`ADMIN_2FA_REQUIRED=1` forces the rule on whatever the setting says.)

## EtelID (the WordPress plugin)
- The EtelID site admin always needs two-step sign-in (kill-switch for emergencies: `define( 'ETELID_REQUIRE_2FA', false );` in wp-config.php, remove it afterwards).
- Staff: EtelID console > Settings > **Two-step sign-in** shows each staff member and whether two-step is on, and has the mode Off / Log / Enforce. "Log" only records; **Enforce** makes the other Etel apps refuse a staff member who has not set it up. Switch to Enforce only after every staff member shows "Two-step ON" (the console asks you to confirm and tells you how many are missing).
- A staff member who lost the phone and the backup codes: EtelID admin > Reset Password > find the member > Reset two-step sign-in. Last resort in phpMyAdmin: `DELETE FROM <prefix>etelid_2fa WHERE member_id = <id>;`
- See also `EtelId/TWO_STEP.md`.

## Backups and the alert
- The daily backup cron (`cron_backup_db.php`) now reports to System health after every run ("Backup job": ok or failed) and needs no key when started by cPanel with `php` directly.
- If the newest backup is **older than 26 hours** (or missing): the Backups row turns red and every admin gets a push "Backup problem" (from the 5-minute monitor cron `cron_ride_monitor.php`), then one more every 12 hours until it is fixed.
- Tests: `php delivery/tests/phase3_unit.php`, `php delivery/tests/backup_watch_test.php` (database), `python3 delivery/tests/admin_2fa_required_smoke.py` (server).
