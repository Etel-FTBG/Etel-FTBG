"""Guards cron_ride_monitor.php never dying silently: every step is wrapped, the heartbeat is written even when a step fails, a shutdown function records a fatal error,
the reason is kept in the heartbeat row ride_monitor_error, and the System health 'Cron job' row shows it."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
cron = read('api/cron_ride_monitor.php')
health = read('api/lib/system_health.php')
bad = []
for needle in ('register_shutdown_function(', "'ride_monitor_error'", 'cs_note(', 'catch (Throwable $e)', "etel_health_heartbeat($pdo, 'ride_monitor'"):
    if needle not in cron:
        bad.append('cron_ride_monitor.php lost: ' + needle)
for step in ('rides_sweep_stuck(', 'rides_monitor_run(', 'etel_backup_watch('):
    m = re.search(r'try \{[^\n]*' + re.escape(step), cron)
    if not m:
        bad.append('cron_ride_monitor.php: ' + step + ' is not inside a try block (a failing step would stop the heartbeat)')
if cron.index("etel_health_heartbeat($pdo, 'ride_monitor'") < cron.index('etel_backup_watch('):
    bad.append('the heartbeat must be written after the steps (and still reached when one fails)')
if "ride_monitor_error" not in health or 'a step fails' not in health:
    bad.append('system_health.php no longer shows why the cron job stops or a step fails')
if re.search(r'[^\x00-\x7f]', health):
    bad.append('system_health.php is not ASCII only')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: cron ride monitor is never silent (guarded)')
