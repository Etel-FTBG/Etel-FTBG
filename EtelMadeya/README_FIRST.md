# 🚨 READ THIS FIRST! 🚨

## What's Happening?

Your Etel Madeya system is **working perfectly on localhost** but **failing on live server** with this error:

```
❌ Connection error. Please try again.
```

## Why Is This Happening?

Your `config.php` file has **localhost database credentials**, but your live server needs **different credentials**.

### Current Config (WRONG for live server):
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'Etel-Madeya');
define('DB_USER', 'root');        // ❌ Wrong for live server
define('DB_PASS', '');            // ❌ Wrong for live server
```

### What You Need (RIGHT for live server):
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'ethel_EtelMadeya');  // ✅ Your actual database name
define('DB_USER', 'ethel_admin');       // ✅ Your actual username
define('DB_PASS', 'your_password');     // ✅ Your actual password
```

---

## 🎯 Fix It in 3 Steps (5 Minutes)

### Step 1: Get Your Credentials
1. Login to cPanel: `https://ethelmarket.com/cpanel`
2. Go to "MySQL Databases"
3. Write down:
   - Database name (from "Current Databases")
   - Username (from "Current Users")
   - Password (reset if forgotten)

### Step 2: Update config.php
1. Open: `Etel-Madeya/includes/config.php`
2. Find lines 10-14
3. Replace with YOUR credentials from Step 1
4. Save and upload to server

### Step 3: Test It
1. Visit: `https://ethelmarket.com/Etel-Madeya/test-db-connection.php`
2. Should see all ✅ (green checkmarks)
3. Try login again!

---

## 📚 Which Guide Should I Read?

### 🔥 **URGENT - Fix Login Now**
→ Read: **NEXT_STEPS.md**
→ Quick fix for current issue

### 🛠️ **Detailed Setup Instructions**
→ Read: **LIVE_SERVER_SETUP.md**
→ Step-by-step troubleshooting

### 📖 **Complete Installation Guide**
→ Read: **INSTALLATION.md**
→ Full installation from scratch

### 🔍 **Having Problems?**
→ Read: **TROUBLESHOOTING.md**
→ Common issues and solutions

### 📋 **Quick Reference**
→ Read: **QUICK_REFERENCE.md**
→ URLs, credentials, commands

---

## 🎓 Understanding the Problem

### Localhost vs Live Server

| Aspect | Localhost | Live Server (cPanel) |
|--------|-----------|---------------------|
| Database Name | `Etel-Madeya` | `prefix_EtelMadeya` |
| Username | `root` | `prefix_username` |
| Password | (empty) | (strong password) |
| URL | `http://localhost/...` | `https://ethelmarket.com/...` |

**The Problem:** Your code is using localhost credentials on the live server!

**The Solution:** Update `config.php` with live server credentials!

---

## 🔑 Your Login Credentials

Once database is connected, use these to login:

### Controller Panel
- URL: `https://ethelmarket.com/Etel-Madeya/controller.php`
- Username: `madeya`
- Password: `CHANGE_ME`

### Admin Panel
- URL: `https://ethelmarket.com/Etel-Madeya/admin/`
- Username: `admin`
- Password: `CHANGE_ME`

⚠️ **Change these passwords after first login!**

---

## ✅ Quick Checklist

Do these in order:

1. [ ] Read **NEXT_STEPS.md** (start here!)
2. [ ] Get database credentials from cPanel
3. [ ] Update `includes/config.php`
4. [ ] Upload updated file to server
5. [ ] Run `test-db-connection.php`
6. [ ] Verify all tests show ✅
7. [ ] Try login again
8. [ ] Delete `test-db-connection.php`
9. [ ] Change default passwords
10. [ ] Celebrate! 🎉

---

## 🆘 Still Stuck?

### Check These First:
1. Did you update `config.php` with YOUR credentials?
2. Did you upload the updated file to the server?
3. Did you run `test-db-connection.php`?
4. What does the test script show?

### Get Help:
- Email: ethelmarket@gmail.com
- Phone: +251-467-34-64-41

**Include:**
- Screenshot of `test-db-connection.php` results
- Screenshot of browser console errors (F12)
- Exact error message you're seeing

---

## 📁 File Structure

```
Etel-Madeya/
├── includes/
│   └── config.php          ← UPDATE THIS FILE!
├── api/
│   └── index.php           (Driver & Controller API)
├── admin/
│   ├── index.php           (Admin panel)
│   └── api.php             (Admin API)
├── assets/
│   ├── css/                (Golden theme styles)
│   ├── js/                 (JavaScript)
│   └── img/                (Logo, images)
├── index.php               (Driver interface)
├── controller.php          (Controller panel)
├── database.sql            (Database structure)
├── test-db-connection.php  (Test script - RUN THIS!)
├── .htaccess               (Apache config)
│
├── README_FIRST.md         ← YOU ARE HERE
├── NEXT_STEPS.md           ← READ THIS NEXT!
├── LIVE_SERVER_SETUP.md    (Troubleshooting)
├── INSTALLATION.md         (Full guide)
├── QUICK_REFERENCE.md      (Quick reference)
└── LOGIN_CREDENTIALS.md    (Login info)
```

---

## 🎯 What You Need to Do RIGHT NOW

1. **Open:** `NEXT_STEPS.md`
2. **Follow:** The 4 steps
3. **Test:** Run test-db-connection.php
4. **Login:** Try controller/admin panels
5. **Done!** 🎉

---

## 💡 Pro Tip

Keep two versions of `config.php`:

**For Localhost:**
```php
define('DB_USER', 'root');
define('DB_PASS', '');
define('SITE_URL', 'http://localhost/Etel-Madeya');
```

**For Live Server:**
```php
define('DB_USER', 'ethel_admin');
define('DB_PASS', 'strong_password');
define('SITE_URL', 'https://ethelmarket.com/Etel-Madeya');
```

Switch between them when deploying!

---

## 🚀 You're Almost There!

The system is **fully built and working**. You just need to:
1. Update database credentials
2. Test connection
3. Login and start using it!

**It's literally a 5-minute fix!**

---

**👉 Next Step: Open NEXT_STEPS.md and follow the instructions!**

**Last Updated:** April 16, 2026
