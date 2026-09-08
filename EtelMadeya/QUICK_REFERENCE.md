# 📋 Quick Reference Card

## 🌐 URLs

### Live Server
- **Driver Interface:** `https://ethelmarket.com/Etel-Madeya/`
- **Controller Panel:** `https://ethelmarket.com/Etel-Madeya/controller.php`
- **Admin Panel:** `https://ethelmarket.com/Etel-Madeya/admin/`
- **cPanel:** `https://ethelmarket.com/cpanel`
- **Test Script:** `https://ethelmarket.com/Etel-Madeya/test-db-connection.php`

### Localhost
- **Driver Interface:** `http://localhost/Etel-Madeya/`
- **Controller Panel:** `http://localhost/Etel-Madeya/controller.php`
- **Admin Panel:** `http://localhost/Etel-Madeya/admin/`

---

## 🔑 Default Login Credentials

### Controller Panel
- **Username:** `madeya`
- **Password:** `CHANGE_ME`

### Admin Panel
- **Username:** `admin`
- **Password:** `CHANGE_ME`

⚠️ **IMPORTANT:** Change these passwords after first login!

---

## 💾 Database Configuration

### Localhost (Development)
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'Etel-Madeya');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### Live Server (Production)
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'prefix_EtelMadeya');  // ← Get from cPanel
define('DB_USER', 'prefix_username');  // ← Get from cPanel
define('DB_PASS', 'your_password');  // ← Get from cPanel
```

**File Location:** `includes/config.php`

---

## 📁 Important Files

### Configuration
- `includes/config.php` - Main configuration (database, site URL)
- `.htaccess` - Apache configuration (routing, security)

### Database
- `database.sql` - Database structure and default data (9 tables)
- `includes/database-new.php` - Database class (PDO)

### Entry Points
- `index.php` - Driver interface
- `controller.php` - Controller panel
- `admin/index.php` - Admin panel

### API Endpoints
- `api/index.php` - Driver & Controller API
- `admin/api.php` - Admin API

### Assets
- `assets/css/` - Stylesheets (golden theme)
- `assets/js/` - JavaScript files
- `assets/img/` - Images (logo, background)

### Documentation
- `NEXT_STEPS.md` - What to do now
- `LIVE_SERVER_SETUP.md` - Fix live server issues
- `INSTALLATION.md` - Complete installation guide
- `LOGIN_CREDENTIALS.md` - Login information
- `TROUBLESHOOTING.md` - Common problems

---

## 🗄️ Database Tables

1. **admin_users** - Admin accounts
2. **controllers** - Controller accounts and stations
3. **vehicle_types** - Vehicle categories (Bajaj, Taxi, etc.)
4. **queue** - Current queue (active vehicles)
5. **report** - Served vehicles history
6. **activity_log** - Admin activity tracking
7. **settings** - System settings
8. **fuel_allocations** - Fuel allocation tracking
9. **notifications** - System notifications

---

## 🛠️ Common Commands

### cPanel Tasks
- **Create Database:** MySQL Databases → Create New Database
- **Create User:** MySQL Databases → Add New User
- **Add User to DB:** MySQL Databases → Add User To Database
- **Import SQL:** phpMyAdmin → Select DB → Import
- **Check Tables:** phpMyAdmin → Select DB → Structure

### File Permissions
- **Folders:** 755
- **Files:** 644
- **Uploads folder:** 755 (writable)

### FTP/File Manager
- **Upload files:** to `public_html/Etel-Madeya/`
- **Edit config:** `includes/config.php`
- **Check .htaccess:** Must be in root folder

---

## 🔍 Troubleshooting Quick Fixes

### "Connection error. Please try again."
→ Update database credentials in `config.php`
→ Run `test-db-connection.php` to diagnose

### "404 Not Found" for API
→ Check if `.htaccess` exists
→ Verify mod_rewrite is enabled

### Logo not showing
→ Check `assets/img/etel-logo.png` exists
→ Clear browser cache (Ctrl+F5)

### Login page loads but login fails
→ Check browser console (F12)
→ Verify database tables exist
→ Run `test-db-connection.php`

### White screen / blank page
→ Enable error display in `config.php`
→ Check PHP error logs in cPanel

---

## 📞 Support Information

- **Email:** ethelmarket@gmail.com
- **Phone:** +251-467-34-64-41
- **Address:** Addis Ababa, Ethiopia
- **Hours:** Mon-Sun: 24/7

---

## ✅ Pre-Launch Checklist

- [ ] Database created in cPanel
- [ ] Database user created with strong password
- [ ] User added to database with ALL PRIVILEGES
- [ ] database.sql imported (9 tables)
- [ ] config.php updated with live server credentials
- [ ] SITE_URL updated to live URL
- [ ] test-db-connection.php shows all ✅
- [ ] Admin login works
- [ ] Controller login works
- [ ] Driver interface works
- [ ] Logo displays correctly
- [ ] test-db-connection.php deleted
- [ ] Default passwords changed
- [ ] SSL certificate installed
- [ ] HTTPS redirect enabled

---

## 🎨 Theme Colors

### Golden Theme
- **Primary Gold:** `#d4a017`
- **Light Gold:** `#ffd700`
- **Bronze:** `#cd7f32`
- **Dark Background:** `#1a1a1a`
- **Dark Brown:** `#2d2416`

### Status Colors
- **Success:** `#4caf50`
- **Warning:** `#ff9800`
- **Error:** `#f44336`
- **Info:** `#2196f3`

---

## 🚀 Quick Start Commands

### Test Database Connection
```
Visit: https://ethelmarket.com/Etel-Madeya/test-db-connection.php
```

### Check API Response
```
Visit: https://ethelmarket.com/Etel-Madeya/api/index.php?action=get_vehicle_types
```

### View Browser Console
```
Press: F12
Tab: Console
```

### Hard Refresh (Clear Cache)
```
Windows: Ctrl + F5
Mac: Cmd + Shift + R
```

---

## 📊 System Requirements

- **PHP:** 7.4+ (8.0+ recommended)
- **MySQL:** 5.7+ (8.0+ recommended)
- **Apache:** with mod_rewrite
- **SSL:** Recommended (free via Let's Encrypt)
- **Disk Space:** 50MB minimum
- **Memory:** 128MB PHP memory limit (256MB recommended)

---

## 🔐 Security Best Practices

1. **Change default passwords** immediately
2. **Use strong database password** (not empty)
3. **Delete test files** after installation
4. **Enable HTTPS** with SSL certificate
5. **Keep backups** of database and files
6. **Update PHP** to latest stable version
7. **Monitor activity logs** regularly
8. **Restrict file permissions** (755/644)

---

**Print this page and keep it handy! 📄**

**Last Updated:** April 16, 2026
