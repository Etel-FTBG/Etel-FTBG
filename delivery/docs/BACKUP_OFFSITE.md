# Keeping a copy of the database backups away from the server

The daily backup (`cron_backup_db.php`) is written on the same server as the database. If that server is lost, the
backups go with it. There are two ways to keep a copy elsewhere. Use the first one on day one; add the second when
you are ready.

## 1. Download a copy (works today, nothing to set up)

Admin -> System health -> Backups -> **Download**. Save the file on your own computer or phone, or in a drive you
control. The health page shows **Backup copy elsewhere**: it turns to a warning when no one has downloaded a copy for
7 days, so it works as a weekly reminder.

## 2. Automatic copy to a bucket (optional)

Any S3-compatible storage works: Backblaze B2, Cloudflare R2, Wasabi, Amazon S3. Create a **private** bucket and an
access key that can write to it, then add these lines to `api/.env`:

```
BACKUP_S3_ENDPOINT=s3.us-west-004.backblazeb2.com   # host only
BACKUP_S3_REGION=us-west-004
BACKUP_S3_BUCKET=your-private-bucket
BACKUP_S3_KEY=your-access-key-id
BACKUP_S3_SECRET=your-secret-key
BACKUP_ENC_PASSPHRASE=a long phrase only you know
```

Every night the new backup is encrypted with the passphrase (it holds phone numbers and password hashes) and
uploaded. The health page shows **Backup copy elsewhere: Sent off the server ...**, or the reason if it fails. A
failed upload never stops the local backup.

**Keep the passphrase somewhere safe, away from the server.** Without it the copy cannot be opened.

### Restoring from a bucket copy

1. Download the `.sql.gz.enc` file from the bucket.
2. Decrypt it (command line, any computer with PHP):
   `php api/decrypt_backup_cli.php etel-XXXX.sql.gz.enc restored.sql.gz "your passphrase"`
3. Import `restored.sql.gz` like a normal backup (phpMyAdmin -> Import reads it directly).
