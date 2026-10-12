# Uptime monitoring for Etel Delivery and Etel Ride

Goal: you hear about an outage from a message on your phone, not from a customer.
Cost: free. Time: about 15 minutes. You need only a browser; nothing is installed on the server.

## 1. What the server gives you

`https://ethelmarket.com/delivery/api/health.php`

| Answer | Meaning |
|---|---|
| HTTP 200 `{"status":"ok"}` | Database answers, disk has room, background jobs are running |
| HTTP 503 `{"status":"down"}` | The database does not answer (the app cannot work) |
| HTTP 503 `{"status":"degraded"}` | The app works but something needs attention: disk almost full (under 3% or 100 MB) or the ride-watch job has not run for 30 minutes |

It shows no private data and changes nothing, so it is safe to leave public.
`disk_free_pct` and `cron_age_min` in the answer tell you which part is the problem.

## 2. Set up the free monitor (UptimeRobot)

1. Create a free account at uptimerobot.com.
2. **Add New Monitor**, type **HTTP(s)**:
   - Name: `Etel API health`
   - URL: `https://ethelmarket.com/delivery/api/health.php`
   - Interval: 5 minutes (free plan)
   - UptimeRobot treats any answer other than 200 as DOWN, so no keyword is needed.
3. Add three more monitors of type **HTTP(s)** for the apps (a plain page check is enough):
   - `https://ethelmarket.com/delivery/ride/`
   - `https://ethelmarket.com/delivery/driver/`
   - `https://ethelmarket.com/delivery/customer.html`
4. **Alert contacts** (My Settings, Alert Contacts, Add):
   - Email: your Etel Group address.
   - Telegram: choose Telegram, press the button, and start the UptimeRobot bot. (You can also add a group, so the whole team gets the alert.)
   - SMS is paid on UptimeRobot; Telegram is free and faster in practice.
5. Attach the contacts to all four monitors. Under each monitor set "send the alert after 2 failed checks" so one slow answer does not wake you.

## 3. Make sure the ride-watch job runs (cPanel)

`health.php` reports `degraded` when this job stops, which is how you find out it died.
cPanel, Cron Jobs, every 5 minutes:

```
/usr/local/bin/php -q /home/ACCOUNT/public_html/delivery/api/cron_ride_monitor.php >/dev/null 2>&1
```

Replace `ACCOUNT` with your cPanel account name (the same account folder you upload to).

## 4. Test it once

1. Open `https://ethelmarket.com/delivery/api/health.php` in your phone browser. You should see `"status":"ok"`.
2. In UptimeRobot press **Pause**, then **Resume** on a monitor to confirm it turns green.
3. To test the alert path, edit the `Etel API health` monitor URL to `.../health.php?x=1`, then change it to `.../nothing.php`: it should go red and a Telegram message should arrive within about 10 minutes. Change it back afterwards.

## 5. What to do when an alert arrives

| You see | First check |
|---|---|
| `down` on health.php | cPanel, MySQL Databases: is the database up? Is the hosting account suspended or over its limits? |
| `degraded`, `disk: low` | cPanel, Disk Usage: delete old backups and logs under `api/` and `public_html` |
| `degraded`, `cron: stale` | cPanel, Cron Jobs: is the line above still there and enabled? |
| Only an app page is down | Reload it yourself; if the page is blank, check the latest deploy (restore the previous files) |
| Everything down | The server or domain: check the hosting provider status page and domain expiry |
