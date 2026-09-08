# 🔑 Fix "Invalid Credentials" Error

## Problem
You're getting "Invalid credentials" when trying to login to both controller and admin panels, even though the database connection is working.

## Cause
The password hashes in the database don't match the expected passwords. This can happen if:
- Database was imported from a different source
- Passwords were changed manually
- Hash algorithm mismatch

## Solution (2 Minutes)

### Step 1: Run Password Reset Script

1. **Visit this URL:**
   ```
   https://ethelmarket.com/Etel-Madeya/reset-passwords.php
   ```

2. **You should see:**
   - ✅ Connected to database
   - ✅ Admin password updated
   - ✅ Controller password updated
   - ✅ Password verification: SUCCESS (for both)

3. **If you see all ✅ (green checkmarks), proceed to Step 2**

### Step 2: Try Login Again

**Controller Panel:**
- URL: `https://ethelmarket.com/Etel-Madeya/controller.php`
- Username: `madeya`
- Password: `CHANGE_ME`

**Admin Panel:**
- URL: `https://ethelmarket.com/Etel-Madeya/admin/`
- Username: `admin`
- Password: `CHANGE_ME`

### Step 3: Delete Security Files

**IMMEDIATELY after successful login, delete these files:**
1. `reset-passwords.php` ⚠️ CRITICAL
2. `test-db-connection.php` ⚠️ CRITICAL

**How to delete:**
- Via cPanel File Manager
- Via FTP
- Via SSH: `rm reset-passwords.php test-db-connection.php`

---

## Alternative: Manual Password Reset via phpMyAdmin

If the script doesn't work, you can reset passwords manually:

### For Admin User

1. **Go to phpMyAdmin** in cPanel
2. **Select database:** `Etel-Madeya`
3. **Click on table:** `admin_users`
4. **Click "Edit"** next to the admin user
5. **In the password field, paste this hash:**
   ```
   $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
   ```
   (This is the hash for password: `CHANGE_ME`)
6. **Click "Go"** to save

### For Controller User

1. **In phpMyAdmin**, click on table: `controllers`
2. **Click "Edit"** next to the madeya user
3. **In the password field, paste this hash:**
   ```
   $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
   ```
   (This is the hash for password: `CHANGE_ME`)
4. **Click "Go"** to save

**Note:** Both use the same hash temporarily. After login, change them to different passwords.

---

## Troubleshooting

### Script Shows "Connection Failed"
- Check `includes/config.php` has correct database credentials
- Run `test-db-connection.php` first to verify connection

### Script Shows "Failed to Update Password"
- Check if tables `admin_users` and `controllers` exist
- Check if users `admin` and `madeya` exist in those tables
- Use manual phpMyAdmin method above

### Login Still Fails After Reset
1. **Clear browser cache:** Ctrl+F5
2. **Check browser console:** F12 → Console tab
3. **Try different browser**
4. **Check API response:**
   - Open browser Network tab (F12 → Network)
   - Try to login
   - Click on the API request
   - Check the response

### "Password Verification: FAILED"
This means the password hash wasn't updated correctly. Use the manual phpMyAdmin method.

---

## After Successful Login

### 1. Delete Security Files ⚠️
```bash
# Delete these files IMMEDIATELY:
- reset-passwords.php
- test-db-connection.php
```

### 2. Change Default Passwords

**Change Admin Password:**
1. Login to admin panel
2. Go to Settings or User Management
3. Change password from `CHANGE_ME` to strong password

**Change Controller Password:**
1. Login to admin panel
2. Go to Controllers section
3. Edit controller `madeya`
4. Change password from `CHANGE_ME` to strong password

### 3. Disable Error Display

Edit `includes/config.php`:
```php
// Change from:
ini_set('display_errors', 1);

// To:
ini_set('display_errors', 0);
```

---

## Security Checklist

After fixing login:

- [ ] Deleted `reset-passwords.php`
- [ ] Deleted `test-db-connection.php`
- [ ] Changed admin password
- [ ] Changed controller password
- [ ] Disabled error display
- [ ] Tested all features work
- [ ] Backed up database

---

## Understanding Password Hashes

PHP uses bcrypt hashing for passwords:
- **Plain password:** `CHANGE_ME`
- **Hashed password:** `$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi`

The hash is what's stored in the database. When you login:
1. You enter plain password: `CHANGE_ME`
2. System hashes it and compares with database hash
3. If they match, login succeeds

**Why "Invalid credentials"?**
- The hash in database doesn't match the plain password
- Solution: Update the hash in database to match the password

---

## Quick Reference

### Default Credentials (After Reset)

| Panel | URL | Username | Password |
|-------|-----|----------|----------|
| Controller | `/controller.php` | `madeya` | `CHANGE_ME` |
| Admin | `/admin/` | `admin` | `CHANGE_ME` |

### Files to Delete

| File | When | Why |
|------|------|-----|
| `reset-passwords.php` | After login works | Can reset passwords |
| `test-db-connection.php` | After connection verified | Shows database info |

### Password Hashes

| Password | Hash |
|----------|------|
| `CHANGE_ME` | `$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi` |
| `CHANGE_ME` | `$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi` |

---

## Need Help?

**Email:** ethelmarket@gmail.com  
**Phone:** +251-467-34-64-41

**Include:**
- Screenshot of reset-passwords.php output
- Screenshot of browser console (F12)
- Which method you tried (script or manual)

---

**Last Updated:** April 16, 2026
