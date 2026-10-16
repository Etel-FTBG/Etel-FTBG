# Uptime monitoring for Etel Delivery and Etel Ride

Goal: you hear about an outage from a message on your phone, not from a customer.
Cost: free. Time: about 15 minutes. You need only a browser; nothing is installed on the server.

## 1. What the server gives you

`https://ethelmarket.com/delivery/api/health.php`

| Answer | Meaning |
|---|---|
| HTTP 200 `{"status":"ok"}` | Database answers, disk has room, background jobs are running, the newest database backup is under 36 hours old |
| HTTP 503 `{"status":"down"}` | The database does not answer (the app cannot work) |
| HTTP 503 `{"status":"degraded"}` | The app works but something needs attention: disk almost full (under 3% or 100 MB), the ride-watch job has not run for 30 minutes, or the newest database backup is older than 36 hours |

It shows no private data and changes nothing, so it is safe to leave public.
`disk_free_pct`, `cron_age_min` and `backup_age_h` in the answer tell you which part is the problem.

## 1b. The public status page

`https://ethelmarket.com/delivery/status.html` shows customers and partners whether ordering, rides and dispatch are working.
It reads the same health check every minute and shows only what matters to them (can people order or ride, is dispatch running).
Disk space and backup age are owner-only warnings: they stay in the health check and Admin, not on this page.
Add it as a fifth UptimeRobot monitor if you want to know the page itself is up.

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
4. **Alert contacts** (Integrations in the left menu). On the free plan these work:
   - **Email**: your Etel Group address (added at sign-up).
   - **Push notifications**: install the UptimeRobot app on your phone (Android or iPhone), sign in, and allow notifications. This is the fastest free alert.
   - **Discord**: Integrations, Chat platforms, Discord, Add (a free Discord server with a channel for alerts also works for a team).
   - Telegram, Slack and Teams are shown but locked: they need a paid plan (Solo or higher).
   - The onboarding checklist offers 25 free SMS credits: finish its steps if you want a text message as a backup.
5. Attach the contacts to all four monitors. Under each monitor set "send the alert after 2 failed checks" so one slow answer does not wake you.

## 3. Make sure the ride-watch job runs (cPanel)

`health.php` reports `degraded` when this job stops, which is how you find out it died.
cPanel, Cron Jobs, every 5 minutes:

```
/usr/local/bin/php -q /home/ACCOUNT/public_html/delivery/api/cron_ride_monitor.php >/dev/null 2>&1
```

Replace `ACCOUNT` with your cPanel account name (the same account folder you upload to).

The daily backup job (once a day, for example 02:30) uses the same cron screen. `health.php` goes red if it stops for more than 36 hours:

```
wget -q -O /dev/null "https://ethelmarket.com/delivery/api/cron_backup_db.php?key=YOUR_CRON_KEY"
```

Use the cron key from `api/.env` (`CRON_DISPATCH_KEY`). Until the first backup exists, `health.php` shows `"backup":"unknown"` and stays green.

## 4. Test it once

1. Open `https://ethelmarket.com/delivery/api/health.php` in your phone browser. You should see `"status":"ok"`.
2. In UptimeRobot press **Pause**, then **Resume** on a monitor to confirm it turns green.
3. To test the alert path, edit the `Etel API health` monitor URL to `.../health.php?x=1`, then change it to `.../nothing.php`: it should go red and a push notification should arrive within about 10 minutes. Change it back afterwards.

## 5. What to do when an alert arrives

| You see | First check |
|---|---|
| `down` on health.php | cPanel, MySQL Databases: is the database up? Is the hosting account suspended or over its limits? |
| `degraded`, `disk: low` | cPanel, Disk Usage: delete old backups and logs under `api/` and `public_html` |
| `degraded`, `cron: stale` | cPanel, Cron Jobs: is the line above still there and enabled? |
| `degraded`, `backup: stale` | cPanel, Cron Jobs: is the daily backup line (`cron_backup_db.php`) still there? Run it once by hand, then check `?action=verify`. Also download the newest backup to your own computer now and then (Admin > System health > backup download). |
| Only an app page is down | Reload it yourself; if the page is blank, check the latest deploy (restore the previous files) |
| Everything down | The server or domain: check the hosting provider status page and domain expiry |
