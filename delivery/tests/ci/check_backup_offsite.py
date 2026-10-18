#!/usr/bin/env python3
"""Backup copies away from the server: the nightly job, the admin download and the health card are all wired up."""
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

cron = read('api/cron_backup_db.php')
if 'etel_backup_push_offsite(' not in cron or "'backup_offsite'" not in cron:
    bad.append('cron_backup_db.php must push the new backup off-site and record the result')
if cron.index('etel_backup_push_offsite(') < cron.index('file_put_contents($dir . \'/\' . $name . \'.json\''):
    bad.append('the off-site push must run after the local backup is complete')
if "'passphrase' => bk_env('BACKUP_ENC_PASSPHRASE')" not in cron:
    bad.append('the off-site copy must be encrypted with BACKUP_ENC_PASSPHRASE')
lib = read('api/lib/backup_offsite.php')
if 'refuse' not in lib.lower() and 'must be encrypted' not in lib:
    bad.append('the off-site push must refuse to send an unencrypted copy')
if 'BACKUP_ENC_PASSPHRASE is not set' not in lib:
    bad.append('missing the passphrase guard in etel_backup_push_offsite')
if "'backup_download'" not in read('api/controllers/RideController.php'):
    bad.append('admin_backup_download must record that a copy was taken')
health = read('api/lib/system_health.php')
if 'etel_health_backup_copy($pdo)' not in health:
    bad.append('the health card must show "Backup copy elsewhere"')
if 'decrypt_backup_cli.php' not in read('docs/BACKUP_OFFSITE.md'):
    bad.append('the restore steps must be documented')
if "PHP_SAPI !== 'cli'" not in read('api/decrypt_backup_cli.php'):
    bad.append('decrypt_backup_cli.php must refuse to run from the web')

if bad:
    print('check_backup_offsite FAILED:\n  ' + '\n  '.join(bad))
    sys.exit(1)
print('check_backup_offsite OK')
