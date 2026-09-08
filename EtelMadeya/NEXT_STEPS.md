# 🎯 NEXT STEPS - Fix Live Server Login

## Current Status

✅ **Working on localhost:**
- Driver interface: `http://localhost/Etel-Madeya/`
- Controller panel: `http://localhost/Etel-Madeya/controller.php`
- Admin panel: `http://localhost/Etel-Madeya/admin/`

❌ **NOT working on live server:**
- Error: "Connection error. Please try again."
- Reason: Database credentials are for localhost, not live server

## What You Need to Do NOW

### 1️⃣ Get Your Database Credentials (2 minutes)

1. Login to cPanel: `https://ethelmarket.com/cpanel`
2. Go to "MySQL Databases"
3. Write down:
   - **Database name:** (look in "Current Databases" section)
   - **Username:** (look in "Current Users" section)
   - **Password:** (if you forgot, reset it)

**Example:**
```
Database: ethel_EtelMadeya
Username: ethel_admin
Password: MyStr0ngP@ssw0rd
```

### 2️⃣ Update config.php (1 minute)

1. Open: `Etel-Madeya/includes/config.php`
2. Find lines 10-14
3. Replace with YOUR credentials from Step 1
4. Save file
5. Upload to server (if editing locally)

**Before:**
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'Etel-Madeya');
define('DB_USER', 'root');
define('DB_PASS', '');
```

**After:**
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'ethel_EtelMadeya');  // ← YOUR database name
define('DB_USER', 'ethel_admin');  // ← YOUR username
define('DB_PASS', 'MyStr0ngP@ssw0rd');  // ← YOUR password
```

### 3️⃣ Test Connection (1 minute)

Visit: `https://ethelmarket.com/Etel-Madeya/test-db-connection.php`

**If you see ✅ (green checkmarks):**
- Success! Go to Step 4

**If you see ❌ (red X marks):**
- Read the error message
- Follow the instructions shown
- Common issues:
  - Database doesn't exist → Create it in cPanel
  - Wrong credentials → Double-check Step 1
  - No tables → Import database.sql in phpMyAdmin

### 4️⃣ Try Login (1 minute)

**Controller Panel:**
- URL: `https://ethelmarket.com/Etel-Madeya/controller.php`
- Username: `madeya`
- Password: `CHANGE_ME`

**Admin Panel:**
- URL: `https://ethelmarket.com/Etel-Madeya/admin/`
- Username: `admin`
- Password: `CHANGE_ME`

**If login works:**
- ✅ Success! You're done!
- Delete `test-db-connection.php` for security

**If login still fails:**
- Press F12 in browser
- Go to "Console" tab
- Try to login
- Look for error messages
- See "Troubleshooting" section below

---

## 📚 Documentation Files

I've created these guides to help you:

1. **LIVE_SERVER_SETUP.md** - Quick fix for current issue (READ THIS FIRST!)
2. **INSTALLATION.md** - Complete installation guide
3. **LOGIN_CREDENTIALS.md** - Default login credentials
4. **TROUBLESHOOTING.md** - Common problems and solutions

---

## 🔍 Troubleshooting

### Problem: "I don't know my database credentials"

**Solution:**
1. Go to cPanel → MySQL Databases
2. Look at "Current Databases" - this is your database name
3. Look at "Current Users" - this is your username
4. If you forgot password, click "Change Password" next to user

### Problem: "Database doesn't exist"

**Solution:**
1. Go to cPanel → MySQL Databases
2. Create new database: `Etel-Madeya`
3. Note the FULL name (may have prefix)
4. Go to phpMyAdmin
5. Select the database
6. Import `database.sql` file

### Problem: "No tables in database"

**Solution:**
1. Go to cPanel → phpMyAdmin
2. Select your database
3. Click "Import" tab
4. Choose `database.sql` file
5. Click "Go"
6. Should create 9 tables

### Problem: "Access denied for user"

**Solution:**
1. Go to cPanel → MySQL Databases
2. Scroll to "Add User To Database"
3. Select your user and database
4. Click "Add"
5. Check "ALL PRIVILEGES"
6. Click "Make Changes"

### Problem: "Still getting connection error"

**Solution:**
1. Check if you uploaded the updated `config.php` to server
2. Clear browser cache (Ctrl+F5)
3. Check browser console for errors (F12)
4. Run `test-db-connection.php` again
5. Take screenshot and contact support

---

## ⚠️ Important Notes

### Database Name Prefix

cPanel automatically adds a prefix to database names:
- You create: `Etel-Madeya`
- Actual name: `ethel_EtelMadeya` (with your cPanel username)

**Always use the FULL name in config.php!**

### Username Prefix

Same for usernames:
- You create: `admin`
- Actual name: `ethel_admin` (with your cPanel username)

**Always use the FULL username in config.php!**

### Password

- Never use empty password on live server
- Use strong password (letters, numbers, symbols)
- Write it down somewhere safe

### SITE_URL

Make sure this is correct in `config.php`:
```php
define('SITE_URL', 'https://ethelmarket.com/Etel-Madeya');
```

NOT:
```php
define('SITE_URL', 'http://localhost/Etel-Madeya');  // ❌ Wrong for live server
```

---

## 🎉 After It Works

Once login is working:

1. **Delete test file:**
   - Delete `test-db-connection.php` (security risk)

2. **Change default passwords:**
   - Admin password: `CHANGE_ME` → strong password
   - Controller password: `CHANGE_ME` → strong password

3. **Test all features:**
   - Driver interface (check queue)
   - Controller panel (register vehicles)
   - Admin panel (manage controllers)

4. **Enable HTTPS:**
   - Get SSL certificate in cPanel (free)
   - Uncomment HTTPS redirect in `.htaccess`

5. **Backup database:**
   - Export from phpMyAdmin
   - Save backup file somewhere safe

---

## 📞 Need Help?

**Before contacting support:**
1. Run `test-db-connection.php`
2. Take screenshot of results
3. Check browser console (F12)
4. Take screenshot of any errors

**Contact:**
- Email: ethelmarket@gmail.com
- Phone: +251-467-34-64-41

---

## ✅ Quick Checklist

- [ ] Got database name from cPanel
- [ ] Got username from cPanel
- [ ] Got/reset password
- [ ] Updated config.php with correct credentials
- [ ] Uploaded config.php to server
- [ ] Ran test-db-connection.php
- [ ] All tests show ✅
- [ ] Login works on controller panel
- [ ] Login works on admin panel
- [ ] Deleted test-db-connection.php

---

**You're almost there! Just update those database credentials and you'll be good to go! 🚀**

**Last Updated:** April 16, 2026
