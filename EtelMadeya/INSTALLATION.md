# Etel Madeya - Installation Guide

This guide will help you install and configure the Etel Madeya Gas Queue Management System.

## 🚀 Quick Start (For Live Server)

### Step 1: Upload Files
Upload all files from `Etel-Madeya` folder to your server:
- **Live Server:** `public_html/Etel-Madeya/` (via FTP or File Manager)
- **Localhost:** `C:\xampp\htdocs\Etel-Madeya\`

### Step 2: Create Database via cPanel

1. **Log in to cPanel** at `https://ethelmarket.com/cpanel`
2. **Find "MySQL Databases"** section
3. **Create Database:**
   - Database Name: `Etel-Madeya` (or with prefix like `ethel_EtelMadeya`)
   - Click "Create Database"
   - **Note the full database name** (it may have a prefix)

4. **Create Database User:**
   - Username: Choose a username (e.g., `etel_admin`)
   - Password: Generate a strong password
   - Click "Create User"
   - **Write down the username and password!**

5. **Add User to Database:**
   - Select the user you created
   - Select the database you created
   - Check "ALL PRIVILEGES"
   - Click "Make Changes"

### Step 3: Import Database

1. **Go to phpMyAdmin** in cPanel
2. **Select your database** (`Etel-Madeya` or with prefix)
3. **Click "Import" tab**
4. **Choose file:** Browse and select `database.sql`
5. **Click "Go"** to import
6. **Wait for success message** (should create 9 tables)

### Step 4: Configure Database Connection

**CRITICAL:** Edit `includes/config.php` with your database credentials:

```php
// Example for cPanel hosting:
define('DB_HOST', 'localhost');  // Usually 'localhost'
define('DB_NAME', 'ethel_EtelMadeya');  // Your FULL database name (with prefix)
define('DB_USER', 'ethel_admin');  // Your database username (with prefix)
define('DB_PASS', 'your_strong_password');  // Your database password
```

**How to find your credentials:**
- Go to cPanel → MySQL Databases
- Look at "Current Databases" section for full database name
- Look at "Current Users" section for full username

### Step 5: Update Site URL

In `includes/config.php`, update:

```php
// For live server:
define('SITE_URL', 'https://ethelmarket.com/Etel-Madeya');

// For localhost:
// define('SITE_URL', 'http://localhost/Etel-Madeya');
```

### Step 6: Test Installation

1. **Visit:** `https://ethelmarket.com/Etel-Madeya/test-db-connection.php`
2. **Check results:**
   - ✅ All green checkmarks = Success!
   - ❌ Red X marks = Follow the instructions shown
3. **IMPORTANT:** Delete `test-db-connection.php` after testing!

### Step 7: Access Your System

**Driver Interface** (Public)
- URL: `https://ethelmarket.com/Etel-Madeya/`
- Drivers check queue position by plate number

**Controller Panel**
- URL: `https://ethelmarket.com/Etel-Madeya/controller.php`
- Username: `madeya`
- Password: `CHANGE_ME`

**Admin Panel**
- URL: `https://ethelmarket.com/Etel-Madeya/admin/`
- Username: `admin`
- Password: `CHANGE_ME`

---

## 🔧 Troubleshooting

### ❌ "Connection error. Please try again."

This is a **database connection error**. Follow these steps:

#### Solution 1: Check Database Credentials

1. **Run test script:** `https://ethelmarket.com/Etel-Madeya/test-db-connection.php`
2. **Look for errors:**
   - "Database does NOT exist" → Create database in cPanel
   - "Connection failed" → Wrong username/password
   - "No tables found" → Import database.sql

#### Solution 2: Verify Database Name

cPanel adds a **prefix** to database names:
- You created: `Etel-Madeya`
- Actual name: `ethel_EtelMadeya` (with your cPanel username prefix)

**To find the correct name:**
1. Go to cPanel → MySQL Databases
2. Look at "Current Databases" section
3. Copy the FULL database name (with prefix)
4. Update `config.php` with the full name

#### Solution 3: Check Database User Permissions

1. Go to cPanel → MySQL Databases
2. Scroll to "Add User To Database"
3. Make sure your user is added to the database
4. Click "Manage" next to the database
5. Ensure "ALL PRIVILEGES" is checked

#### Solution 4: Verify Database Import

1. Go to phpMyAdmin
2. Select your database
3. Check if these 9 tables exist:
   - `admin_users`
   - `controllers`
   - `vehicle_types`
   - `queue`
   - `report`
   - `activity_log`
   - `settings`
   - `fuel_allocations`
   - `notifications`
4. If tables are missing, re-import `database.sql`

### ❌ Login Page Shows But Login Fails

**Check browser console:**
1. Press F12 in browser
2. Go to "Console" tab
3. Try to login
4. Look for error messages (red text)
5. Common errors:
   - "404 Not Found" → API routing issue
   - "500 Internal Server Error" → PHP error
   - "Connection refused" → Database issue

**Check API endpoints:**
- Visit: `https://ethelmarket.com/Etel-Madeya/api/index.php?action=get_vehicle_types`
- Should show JSON response, not 404 error

### ❌ "404 Not Found" for API Calls

**.htaccess not working:**

1. **Check if .htaccess exists:**
   - Use FTP or File Manager
   - Look for `.htaccess` in `Etel-Madeya` folder
   - If missing, upload it from your local files

2. **Check mod_rewrite:**
   - Contact your hosting support
   - Ask: "Is mod_rewrite enabled for my account?"

3. **Alternative: Direct API access**
   - Edit JavaScript files to use direct paths
   - Change: `/api/?action=login`
   - To: `/api/index.php?action=login`

### ❌ Logo Not Showing

1. **Check file exists:**
   - Path: `assets/img/etel-logo.png`
   - Upload if missing

2. **Check file permissions:**
   - File: 644
   - Folder: 755

3. **Clear browser cache:**
   - Press Ctrl+F5 to hard refresh

### ❌ White Screen / Blank Page

**Enable error display:**

1. Edit `includes/config.php`
2. Find these lines:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```
3. Make sure they are NOT commented out
4. Refresh page to see actual error

**Check PHP version:**
- Requires PHP 7.4 or higher
- Check in cPanel → Select PHP Version

---

## 🔒 Security Checklist

After installation, complete these security steps:

### 1. Change Default Passwords

**Admin Password:**
1. Login to admin panel
2. Go to Settings or Profile
3. Change password from `CHANGE_ME` to strong password

**Controller Password:**
1. Login to admin panel
2. Go to Controllers section
3. Edit controller `madeya`
4. Change password from `CHANGE_ME`

### 2. Update Database Credentials

Never use these in production:
- ❌ Username: `root`
- ❌ Password: (empty)

Use strong credentials from cPanel.

### 3. Delete Test Files

After successful installation, delete:
- `test-db-connection.php`
- `INSTALLATION.md` (this file)
- `README.md`
- `database.sql` (keep backup elsewhere)

### 4. Secure .htaccess

Verify `.htaccess` blocks access to:
- `.sql` files
- `.md` files
- `.txt` files
- `config.php`

Test by visiting:
- `https://ethelmarket.com/Etel-Madeya/database.sql` (should be blocked)
- `https://ethelmarket.com/Etel-Madeya/includes/config.php` (should be blocked)

### 5. Enable HTTPS

1. Get SSL certificate (free from Let's Encrypt via cPanel)
2. Update `config.php`:
```php
define('SITE_URL', 'https://ethelmarket.com/Etel-Madeya');
```
3. Uncomment HTTPS redirect in `.htaccess`:
```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### 6. Set File Permissions

**Via FTP or File Manager:**
- Folders: 755
- Files: 644
- `uploads/` folder: 755 (writable)

**Via SSH:**
```bash
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod 755 uploads/
```

---

## 📋 System Requirements

### Minimum Requirements
- **PHP:** 7.4 or higher
- **MySQL:** 5.7 or higher
- **Web Server:** Apache with mod_rewrite
- **Disk Space:** 50MB
- **Memory:** 128MB PHP memory limit

### Recommended
- **PHP:** 8.0+
- **MySQL:** 8.0+
- **SSL Certificate:** Yes (HTTPS)
- **Memory:** 256MB PHP memory limit

### PHP Extensions Required
- PDO
- PDO_MySQL
- JSON
- Session
- GD (for image handling)

---

## 📞 Support

**Contact Information:**
- Email: ethelmarket@gmail.com
- Phone: +251-467-34-64-41
- Address: Addis Ababa, Ethiopia

**Before Contacting Support:**
1. Run `test-db-connection.php` and note results
2. Check browser console for errors (F12)
3. Check PHP error logs in cPanel
4. Note exact error messages

---

## 📝 Default Login Credentials

**IMPORTANT:** Change these after first login!

| Panel | URL | Username | Password |
|-------|-----|----------|----------|
| Admin | `/admin/` | `admin` | `CHANGE_ME` |
| Controller | `/controller.php` | `madeya` | `CHANGE_ME` |

---

## 🎯 Quick Checklist

Use this checklist to verify installation:

- [ ] Files uploaded to server
- [ ] Database created in cPanel
- [ ] Database user created with strong password
- [ ] User added to database with ALL PRIVILEGES
- [ ] database.sql imported successfully (9 tables)
- [ ] config.php updated with correct DB credentials
- [ ] SITE_URL updated in config.php
- [ ] test-db-connection.php shows all ✅
- [ ] test-db-connection.php deleted after testing
- [ ] Admin panel login works
- [ ] Controller panel login works
- [ ] Driver interface loads correctly
- [ ] Logo displays on all pages
- [ ] Default passwords changed
- [ ] SSL certificate installed (HTTPS)

---

**Version:** 1.0.0  
**Last Updated:** April 16, 2026  
**Tested On:** cPanel hosting, XAMPP localhost
