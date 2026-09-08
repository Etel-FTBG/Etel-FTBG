# 🚨 URGENT: Fix "Connection error" on Live Server

You're getting "Connection error. Please try again." because the database credentials are wrong for your live server.

## ⚡ Quick Fix (5 Minutes)

### Step 1: Find Your Database Credentials

1. **Login to cPanel:** `https://ethelmarket.com/cpanel`
2. **Go to:** MySQL Databases
3. **Look at "Current Databases"** section
   - Find database with "Etel" or "Madeya" in the name
   - Copy the FULL name (example: `ethel_EtelMadeya`)
4. **Look at "Current Users"** section
   - Find the username you created
   - Copy the FULL username (example: `ethel_admin`)
5. **Find password:**
   - If you forgot it, click "Change Password" next to the user
   - Set a new strong password and write it down

### Step 2: Update config.php

1. **Open:** `includes/config.php` (via File Manager or FTP)
2. **Find these lines** (around line 10-14):
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'Etel-Madeya');
define('DB_USER', 'root');
define('DB_PASS', '');
```

3. **Replace with YOUR credentials:**
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'ethel_EtelMadeya');  // ← Your FULL database name from cPanel
define('DB_USER', 'ethel_admin');  // ← Your FULL username from cPanel
define('DB_PASS', 'your_password_here');  // ← Your database password
```

4. **Save the file**

### Step 3: Test Connection

1. **Visit:** `https://ethelmarket.com/Etel-Madeya/test-db-connection.php`
2. **Check results:**
   - ✅ All green = Success! Go to Step 4
   - ❌ Red errors = See "Common Errors" below

### Step 4: Try Login Again

1. **Controller:** `https://ethelmarket.com/Etel-Madeya/controller.php`
   - Username: `madeya`
   - Password: `CHANGE_ME`

2. **Admin:** `https://ethelmarket.com/Etel-Madeya/admin/`
   - Username: `admin`
   - Password: `CHANGE_ME`

---

## 🔍 Common Errors

### Error: "Database 'Etel-Madeya' does NOT exist"

**Problem:** Database not created or wrong name

**Solution:**
1. Go to cPanel → MySQL Databases
2. Create new database: `Etel-Madeya`
3. Note the FULL name (may have prefix like `ethel_EtelMadeya`)
4. Update `config.php` with the FULL name

### Error: "Connection failed: Access denied"

**Problem:** Wrong username or password

**Solution:**
1. Go to cPanel → MySQL Databases
2. Check "Current Users" for correct username
3. Reset password if needed
4. Make sure user is added to database (see "Add User To Database" section)

### Error: "No tables found in database"

**Problem:** Database exists but empty

**Solution:**
1. Go to cPanel → phpMyAdmin
2. Select your database
3. Click "Import" tab
4. Upload `database.sql` file
5. Click "Go"
6. Should create 9 tables

### Error: "User not added to database"

**Problem:** User exists but not linked to database

**Solution:**
1. Go to cPanel → MySQL Databases
2. Scroll to "Add User To Database"
3. Select your user
4. Select your database
5. Click "Add"
6. Check "ALL PRIVILEGES"
7. Click "Make Changes"

---

## 📋 Checklist

Before asking for help, verify:

- [ ] Database exists in cPanel
- [ ] Database user exists in cPanel
- [ ] User is added to database with ALL PRIVILEGES
- [ ] database.sql is imported (9 tables exist)
- [ ] config.php has CORRECT database name (with prefix)
- [ ] config.php has CORRECT username (with prefix)
- [ ] config.php has CORRECT password
- [ ] test-db-connection.php shows all ✅

---

## 🎯 What's the Problem?

Your localhost uses:
- Database: `Etel-Madeya`
- Username: `root`
- Password: (empty)

Your live server (cPanel) uses:
- Database: `prefix_EtelMadeya` (with your cPanel username prefix)
- Username: `prefix_username` (with your cPanel username prefix)
- Password: (strong password you created)

**You MUST update config.php with the live server credentials!**

---

## 💡 Pro Tip

Keep TWO versions of config.php:

**config-localhost.php** (for local development):
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'Etel-Madeya');
define('DB_USER', 'root');
define('DB_PASS', '');
define('SITE_URL', 'http://localhost/Etel-Madeya');
```

**config-live.php** (for live server):
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'ethel_EtelMadeya');
define('DB_USER', 'ethel_admin');
define('DB_PASS', 'strong_password_here');
define('SITE_URL', 'https://ethelmarket.com/Etel-Madeya');
```

Then rename the appropriate one to `config.php` when deploying.

---

## 🆘 Still Not Working?

1. **Run test script:** `https://ethelmarket.com/Etel-Madeya/test-db-connection.php`
2. **Take screenshot** of the results
3. **Check browser console:**
   - Press F12
   - Go to "Console" tab
   - Try to login
   - Take screenshot of any red errors
4. **Contact support** with screenshots

---

**Last Updated:** April 16, 2026
