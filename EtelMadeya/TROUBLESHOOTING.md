# Etel Madeya - Troubleshooting Guide

Complete guide to fixing common issues with the Etel Madeya Gas Queue Management System.

---

## 🔥 CRITICAL ISSUES

### ❌ "Connection error. Please try again."

**Symptom:** Login fails with connection error on live server

**Cause:** Database credentials in `config.php` are wrong for live server

**Solution:**

1. **Get correct credentials from cPanel:**
   - Login to cPanel: `https://ethelmarket.com/cpanel`
   - Go to "MySQL Databases"
   - Note database name (with prefix, e.g., `ethel_EtelMadeya`)
   - Note username (with prefix, e.g., `ethel_admin`)
   - Note/reset password

2. **Update config.php:**
   ```php
   // Edit: includes/config.php (lines 10-14)
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'ethel_EtelMadeya');  // ← Your full database name
   define('DB_USER', 'ethel_admin');  // ← Your full username
   define('DB_PASS', 'your_password');  // ← Your password
   ```

3. **Test connection:**
   - Visit: `https://ethelmarket.com/Etel-Madeya/test-db-connection.php`
   - Should show all ✅ (green checkmarks)

4. **Try login again**

**See also:** LIVE_SERVER_SETUP.md, NEXT_STEPS.md

---

### ❌ Database Does Not Exist

**Symptom:** test-db-connection.php shows "Database does NOT exist"

**Cause:** Database not created in cPanel

**Solution:**

1. **Create database:**
   - Go to cPanel → MySQL Databases
   - Create new database: `Etel-Madeya`
   - Note the FULL name (may have prefix)

2. **Create user:**
   - In same page, create new user
   - Use strong password
   - Write down username and password

3. **Add user to database:**
   - Scroll to "Add User To Database"
   - Select your user and database
   - Click "Add"
   - Check "ALL PRIVILEGES"
   - Click "Make Changes"

4. **Import database:**
   - Go to cPanel → phpMyAdmin
   - Select your database
   - Click "Import" tab
   - Choose `database.sql` file
   - Click "Go"
   - Should create 9 tables

5. **Update config.php** with correct credentials

6. **Test again**

---

### ❌ No Tables Found in Database

**Symptom:** test-db-connection.php shows "No tables found"

**Cause:** Database exists but `database.sql` not imported

**Solution:**

1. **Go to phpMyAdmin:**
   - cPanel → phpMyAdmin
   - Select your database

2. **Import SQL file:**
   - Click "Import" tab
   - Click "Choose File"
   - Select `database.sql`
   - Click "Go"

3. **Verify tables created:**
   - Click "Structure" tab
   - Should see 9 tables:
     - admin_users
     - controllers
     - vehicle_types
     - queue
     - report
     - activity_log
     - settings
     - fuel_allocations
     - notifications

4. **Test connection again**

---

### ❌ Access Denied for User

**Symptom:** "Access denied for user 'username'@'localhost'"

**Cause:** Wrong username/password or user not added to database

**Solution:**

1. **Verify credentials:**
   - Go to cPanel → MySQL Databases
   - Check "Current Users" for correct username
   - Note the FULL username (with prefix)

2. **Reset password if needed:**
   - Click "Change Password" next to user
   - Set new strong password
   - Write it down

3. **Verify user is added to database:**
   - Scroll to "Current Databases"
   - Look for your database
   - Check if your user is listed under "Privileged Users"
   - If not, add user to database (see above)

4. **Update config.php** with correct credentials

5. **Test connection**

---

## 🌐 LOGIN ISSUES

### ❌ Login Page Loads But Login Fails

**Symptom:** Login form appears but clicking login does nothing or shows error

**Cause:** JavaScript error, API routing issue, or database problem

**Solution:**

1. **Check browser console:**
   - Press F12
   - Go to "Console" tab
   - Try to login
   - Look for red error messages

2. **Common console errors:**

   **"404 Not Found" for API:**
   - .htaccess not working
   - Solution: Check if `.htaccess` exists in root folder
   - Contact hosting support to enable mod_rewrite

   **"500 Internal Server Error":**
   - PHP error
   - Solution: Check PHP error logs in cPanel
   - Enable error display in `config.php`

   **"Connection refused":**
   - Database connection failed
   - Solution: Run `test-db-connection.php`

3. **Test API directly:**
   - Visit: `https://ethelmarket.com/Etel-Madeya/api/index.php?action=get_vehicle_types`
   - Should show JSON response
   - If 404 error, .htaccess not working

4. **Check database connection:**
   - Run `test-db-connection.php`
   - Verify all tests pass

---

### ❌ Invalid Credentials Error

**Symptom:** "Invalid credentials" message when logging in

**Cause:** Wrong username/password or database not imported

**Solution:**

1. **Verify default credentials:**

   **Controller:**
   - Username: `madeya`
   - Password: `CHANGE_ME`

   **Admin:**
   - Username: `admin`
   - Password: `CHANGE_ME`

2. **Check if tables exist:**
   - Run `test-db-connection.php`
   - Should show admin_users and controllers tables
   - Should list default users

3. **If tables missing:**
   - Import `database.sql` in phpMyAdmin

4. **If tables exist but login fails:**
   - Check browser console for errors
   - Verify API is working (see above)

---

## 🎨 DISPLAY ISSUES

### ❌ Logo Not Showing

**Symptom:** Logo image doesn't appear on pages

**Cause:** Logo file missing or wrong path

**Solution:**

1. **Check if file exists:**
   - Path: `assets/img/etel-logo.png`
   - Upload if missing

2. **Check file permissions:**
   - File: 644
   - Folder: 755

3. **Clear browser cache:**
   - Press Ctrl+F5 (hard refresh)

4. **Check image path in code:**
   - Should be: `assets/img/etel-logo.png`
   - Not: `assets/images/etel-logo.png`

---

### ❌ Styling Broken / No Golden Theme

**Symptom:** Page looks plain, no golden colors or styling

**Cause:** CSS files not loading

**Solution:**

1. **Check browser console:**
   - Press F12 → Console
   - Look for 404 errors for CSS files

2. **Verify CSS files exist:**
   - `assets/css/driver-style.css`
   - `assets/css/controller-style.css`
   - `assets/css/admin-style.css`

3. **Check file permissions:**
   - Files: 644
   - Folders: 755

4. **Clear browser cache:**
   - Ctrl+F5

5. **Check SITE_URL in config.php:**
   ```php
   define('SITE_URL', 'https://ethelmarket.com/Etel-Madeya');
   ```
   - Must match your actual URL

---

### ❌ White Screen / Blank Page

**Symptom:** Page is completely blank

**Cause:** PHP fatal error

**Solution:**

1. **Enable error display:**
   - Edit `includes/config.php`
   - Find these lines:
   ```php
   ini_set('display_errors', 1);
   error_reporting(E_ALL);
   ```
   - Make sure they're NOT commented out

2. **Refresh page:**
   - Should now show actual error message

3. **Common errors:**

   **"Fatal error: Call to undefined function":**
   - Missing PHP extension
   - Solution: Enable PDO, PDO_MySQL in cPanel

   **"Parse error: syntax error":**
   - Code syntax error
   - Solution: Check recent file edits

   **"Fatal error: require_once(): Failed opening":**
   - File path wrong
   - Solution: Check file exists and path is correct

4. **Check PHP error logs:**
   - cPanel → Error Logs
   - Look for recent errors

---

## 🔧 FUNCTIONALITY ISSUES

### ❌ Driver Can't Check Queue Position

**Symptom:** Entering plate number shows "Vehicle not found"

**Cause:** Vehicle not registered in queue

**Solution:**

1. **Register vehicle first:**
   - Login to controller panel
   - Register the vehicle
   - Note the plate number

2. **Check plate format:**
   - Should be: `AA-1-12345` or similar
   - Case-sensitive

3. **Verify vehicle in database:**
   - phpMyAdmin → queue table
   - Check if plate_number exists
   - Status should be 'queued'

---

### ❌ Controller Can't Register Vehicles

**Symptom:** Registration form doesn't work or shows error

**Cause:** JavaScript error or API issue

**Solution:**

1. **Check browser console:**
   - F12 → Console
   - Try to register
   - Look for errors

2. **Verify API works:**
   - Visit: `https://ethelmarket.com/Etel-Madeya/api/index.php?action=get_vehicle_types`
   - Should show JSON with vehicle types

3. **Check if vehicle_types table has data:**
   - phpMyAdmin → vehicle_types table
   - Should have default types (Bajaj, Taxi, etc.)
   - If empty, re-import database.sql

---

### ❌ Admin Can't Manage Controllers

**Symptom:** Admin panel loads but can't create/edit controllers

**Cause:** API error or permissions issue

**Solution:**

1. **Check browser console:**
   - F12 → Console
   - Try to create controller
   - Look for errors

2. **Verify admin API:**
   - Visit: `https://ethelmarket.com/Etel-Madeya/admin/api.php?action=check_session`
   - Should show JSON response

3. **Check admin session:**
   - Make sure you're logged in
   - Try logging out and back in

---

## 🔐 SECURITY ISSUES

### ⚠️ Can't Access .htaccess Protected Files

**Symptom:** Trying to access database.sql or config.php shows 403 Forbidden

**Cause:** .htaccess security rules working correctly

**Solution:**

This is CORRECT behavior! These files should NOT be accessible via browser.

If you need to access them:
- Use FTP or File Manager in cPanel
- Edit via cPanel File Manager
- Download via FTP

---

### ⚠️ Session Timeout / Logged Out Automatically

**Symptom:** Gets logged out after short time

**Cause:** Session timeout setting

**Solution:**

1. **Increase session timeout:**
   - Edit `includes/config.php`
   - Find: `define('SESSION_TIMEOUT', 3600 * 8);`
   - Change to longer time (in seconds)
   - 3600 = 1 hour, 3600 * 8 = 8 hours

2. **Check PHP session settings:**
   - cPanel → Select PHP Version → Options
   - Check `session.gc_maxlifetime`
   - Should be at least 3600

---

## 🌍 HOSTING ISSUES

### ❌ 404 Error for All Pages

**Symptom:** All pages show 404 Not Found

**Cause:** .htaccess not working or mod_rewrite disabled

**Solution:**

1. **Check if .htaccess exists:**
   - Use File Manager or FTP
   - Look in root `Etel-Madeya` folder
   - Upload if missing

2. **Contact hosting support:**
   - Ask: "Is mod_rewrite enabled for my account?"
   - Ask: "Are .htaccess files allowed?"

3. **Test .htaccess:**
   - Add this line at top of .htaccess:
   ```apache
   # Test comment
   ```
   - If you get 500 error, .htaccess is working
   - If no change, .htaccess is ignored

---

### ❌ PHP Version Too Old

**Symptom:** Errors about syntax or functions not available

**Cause:** PHP version below 7.4

**Solution:**

1. **Check PHP version:**
   - cPanel → Select PHP Version
   - Or create file `phpinfo.php`:
   ```php
   <?php phpinfo(); ?>
   ```
   - Visit it in browser

2. **Upgrade PHP:**
   - cPanel → Select PHP Version
   - Choose PHP 7.4 or higher (8.0+ recommended)
   - Click "Set as current"

3. **Test again**

---

### ❌ Upload Limit Too Small

**Symptom:** Can't import database.sql (file too large)

**Cause:** PHP upload limit too low

**Solution:**

1. **Check current limits:**
   - cPanel → Select PHP Version → Options
   - Look for `upload_max_filesize` and `post_max_size`

2. **Increase limits:**
   - Set both to at least 10M
   - Click "Save"

3. **Alternative: Use phpMyAdmin:**
   - Split database.sql into smaller files
   - Import one at a time

---

## 🔍 DIAGNOSTIC TOOLS

### Test Database Connection
```
https://ethelmarket.com/Etel-Madeya/test-db-connection.php
```
Shows detailed database connection status

### Test API Endpoints
```
https://ethelmarket.com/Etel-Madeya/api/index.php?action=get_vehicle_types
https://ethelmarket.com/Etel-Madeya/admin/api.php?action=check_session
```
Should return JSON responses

### Check PHP Info
Create `phpinfo.php`:
```php
<?php phpinfo(); ?>
```
Shows PHP configuration

### Browser Console
Press F12 → Console tab
Shows JavaScript errors

### Network Tab
Press F12 → Network tab
Shows which files are loading/failing

---

## 📞 GETTING HELP

### Before Contacting Support

Collect this information:

1. **Run test script:**
   - `test-db-connection.php`
   - Take screenshot

2. **Check browser console:**
   - Press F12 → Console
   - Take screenshot of errors

3. **Check PHP error logs:**
   - cPanel → Error Logs
   - Copy recent errors

4. **Note exact error message:**
   - What page?
   - What action?
   - Exact error text?

### Contact Information

- **Email:** ethelmarket@gmail.com
- **Phone:** +251-467-34-64-41
- **Hours:** Mon-Sun: 24/7

### Include in Support Request

- Description of problem
- Steps to reproduce
- Screenshots of errors
- Browser and device info
- What you've already tried

---

## ✅ PREVENTION CHECKLIST

### After Installation
- [ ] Change default passwords
- [ ] Delete test-db-connection.php
- [ ] Enable HTTPS
- [ ] Set proper file permissions
- [ ] Test all features
- [ ] Backup database

### Regular Maintenance
- [ ] Backup database weekly
- [ ] Check error logs monthly
- [ ] Update PHP version when available
- [ ] Monitor disk space
- [ ] Test login regularly

### Before Making Changes
- [ ] Backup database
- [ ] Backup files
- [ ] Test on localhost first
- [ ] Document changes
- [ ] Have rollback plan

---

## 📚 RELATED DOCUMENTATION

- **README_FIRST.md** - Start here
- **NEXT_STEPS.md** - Fix current issues
- **LIVE_SERVER_SETUP.md** - Live server setup
- **INSTALLATION.md** - Complete installation
- **QUICK_REFERENCE.md** - Quick reference
- **LOGIN_CREDENTIALS.md** - Login info

---

**Last Updated:** April 16, 2026  
**Version:** 1.0.0
