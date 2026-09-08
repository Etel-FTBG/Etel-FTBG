# Etel Gebeya — Step 1: Install

This is the very first thing you should be able to load. It will:

1. Check PHP version + required extensions
2. Connect to MySQL with the credentials you enter
3. Create the database (if missing)
4. Run `database/schema.sql` and `database/seed.sql`
5. Create the admin account
6. Drop you on a "System is ready" page

## Run it (dev)

From the project root:

```bash
cd C:\Users\dagianan\Downloads\EtelGebeya\etel-gebeya
php -S localhost:8080 -t public public/index.php
```

Then open <http://localhost:8080/install>.

## What you need ready

- PHP 8.0+ with `pdo_mysql`, `mbstring`, `gd`, `json`
- MySQL 5.7+ or MariaDB 10.3+
- A database user that can `CREATE DATABASE`
- The `config/` and `storage/` directories writable by the PHP process
  (on Windows: usually fine out of the box; on Linux: `chmod -R 775 config storage`)

## After install

You'll see buttons for Homepage, Login, Admin, and Become a vendor. We haven't built
any of those yet — this step is purely the foundation.

If something fails, the form will keep your input and show the error.
