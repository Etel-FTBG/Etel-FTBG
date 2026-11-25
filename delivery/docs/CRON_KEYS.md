# Cron keys: keep the secret out of the web address

A key in the address (`...?key=SECRET`) is written in the server's access log, browser history and any proxy. The cron jobs now read the key from a **header**.
A key still found in the address is handled by **Etel Shield > "Secret key in a web address"** (Allow / Watch / Refuse). It starts on **Watch**: the old address still works
and each use is written in the security log (filter "Secret key in an address", the rule name shows the file).

## The cron commands (cPanel > Cron Jobs). Replace YOUR_CRON_KEY with CRON_DISPATCH_KEY from api/.env

Keep the same times you use now; only the command changes.

```
wget -q -O /dev/null --header="X-Cron-Key: YOUR_CRON_KEY" "https://ethelmarket.com/delivery/api/cron_dispatch_scheduled.php"
wget -q -O /dev/null --header="X-Cron-Key: YOUR_CRON_KEY" "https://ethelmarket.com/delivery/api/cron_ride_monitor.php"
wget -q -O /dev/null --header="X-Cron-Key: YOUR_CRON_KEY" "https://ethelmarket.com/delivery/api/cron_close_stuck_rides.php"
wget -q -O /dev/null --header="X-Cron-Key: YOUR_CRON_KEY" "https://ethelmarket.com/delivery/api/cron_chapa_reconcile.php"
wget -q -O /dev/null --header="X-Cron-Key: YOUR_CRON_KEY" "https://ethelmarket.com/delivery/api/cron_field_agents.php"
wget -q -O /dev/null --header="X-Cron-Key: YOUR_CRON_KEY" "https://ethelmarket.com/delivery/api/equb_cron.php"
wget -q -O /dev/null --header="X-Cron-Key: YOUR_CRON_KEY" "https://ethelmarket.com/delivery/api/cron_backup_db.php"
```

(`curl -s -H "X-Cron-Key: YOUR_CRON_KEY" "URL" > /dev/null` does the same.) Only run the ones you already run.
The jobs run from the command line (`php .../cron_xxx.php`) need no key at all and need no change.

## Diagnose and etel-os

`diagnose.php` and `etel-os.php` take the key in the header `X-Etel-Key` (`etel-os.php` always did). The updater takes its password in `X-Auth` or the form field as before.

## Switching to Refuse
1. Change each cron job in cPanel to the header form above.
2. Wait a day. Etel Shield > Security log > filter "Secret key in an address" must stay empty.
3. Set "Secret key in a web address" to **Refuse** and Save. A key in the address is then ignored.
If a job stops working: set it back to Watch (or Allow), fix the command, try again.

If a host strips the `X-Cron-Key` header (rare), the job gets "403": use the old address with Watch, or tell the developer.
