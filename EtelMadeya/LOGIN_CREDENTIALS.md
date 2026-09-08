# 🔐 ETEL MADEYA - LOGIN CREDENTIALS

---

## 🌐 WEBSITE URLS

### Driver Interface (Public - No Login Required)
```
http://localhost/Etel-Madeya/
```
**What drivers can do:**
- ✅ Check their queue position by plate number
- ❌ Cannot register (only controllers can register vehicles)

---

### Controller Panel
```
http://localhost/Etel-Madeya/controller.php
```

**Login Credentials:**
- **Username:** `madeya`
- **Password:** `CHANGE_ME`

**What controllers can do:**
- ✅ Register vehicles in queue
- ✅ Serve vehicles
- ✅ Move vehicles to VIP/Waiting queue
- ✅ Bulk import vehicles (CSV)
- ✅ View queue statistics
- ✅ Manage fuel allocation

---

### Admin Panel
```
http://localhost/Etel-Madeya/admin/
```

**Login Credentials:**
- **Username:** `admin`
- **Password:** `CHANGE_ME`

**What admins can do:**
- ✅ Manage controllers (add, edit, delete)
- ✅ Manage vehicle types
- ✅ Monitor all queues across stations
- ✅ Generate reports
- ✅ Configure system settings
- ✅ View activity logs

---

## 🔄 WORKFLOW

### 1. Driver Arrives at Station
- Driver goes to station physically
- Controller registers the vehicle

### 2. Controller Registers Vehicle
- Controller logs into controller panel
- Clicks "Add Vehicle" or uses bulk import
- Enters plate number and vehicle type
- Vehicle gets queue position

### 3. Driver Checks Position
- Driver opens: `http://localhost/Etel-Madeya/`
- Clicks "Check Position"
- Enters plate number
- Sees current position in queue

### 4. Controller Serves Vehicle
- When vehicle's turn comes
- Controller clicks "Serve" button
- Enters fuel amount (optional)
- Vehicle is marked as served

---

## ⚠️ IMPORTANT SECURITY NOTES

### ⚠️ CHANGE DEFAULT PASSWORDS IMMEDIATELY!

**After first login, change these passwords:**

### Change Controller Password:
1. Login to controller panel
2. Go to settings (if available) or contact admin
3. Admin can change password from admin panel

### Change Admin Password:
1. Login to admin panel
2. Go to Settings or Profile
3. Change password
4. Or update directly in database:

```sql
-- Update admin password in database
UPDATE admin_users 
SET password = '$2y$12$YOUR_NEW_HASHED_PASSWORD' 
WHERE username = 'admin';
```

To generate new password hash in PHP:
```php
<?php
echo password_hash('your_new_password', PASSWORD_DEFAULT);
?>
```

---

## 📊 DATABASE ACCESS

**Database Name:** `Etel-Madeya`

**phpMyAdmin:**
```
http://localhost/phpmyadmin
```

**Default MySQL Credentials:**
- **Username:** `root`
- **Password:** (empty) or your MySQL password

---

## 🎯 QUICK TEST

### Test Driver Interface:
1. Open: `http://localhost/Etel-Madeya/`
2. Should see golden theme with sparkles
3. Click "Check Position"
4. Try entering a plate number (won't find anything yet)

### Test Controller Login:
1. Open: `http://localhost/Etel-Madeya/controller.php`
2. Enter username: `madeya`
3. Enter password: `CHANGE_ME`
4. Click "Login"
5. Should see controller dashboard

### Test Admin Login:
1. Open: `http://localhost/Etel-Madeya/admin/`
2. Enter username: `admin`
3. Enter password: `CHANGE_ME`
4. Click "Login"
5. Should see admin dashboard

---

## 🐛 TROUBLESHOOTING

### Logo Not Showing?
✅ **FIXED!** Logo is now at: `assets/img/etel-logo.png`

### Can't Login?
- Check username and password (case-sensitive)
- Clear browser cache
- Check database is imported
- Verify database connection in `includes/config.php`

### Driver Can Register?
✅ **FIXED!** Registration removed from driver interface
- Only controllers can register vehicles now

### Database Connection Error?
Edit `includes/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'Etel-Madeya');
define('DB_USER', 'root');
define('DB_PASS', ''); // Your MySQL password
```

---

## 📝 DEFAULT DATA IN DATABASE

### Controllers Table:
- 1 controller: `madeya` / `CHANGE_ME`

### Admin Users Table:
- 1 admin: `admin` / `CHANGE_ME`

### Vehicle Types Table:
- Bajaj (ባጃጅ)
- Taxi (ታክሲ)
- Private Car (የግል መኪና)
- Minibus (ሚኒባስ)
- Truck (ጭነት)
- Motorcycle (ሞተርሳይክል)

---

## 🎉 SUMMARY

✅ **Logo:** Fixed and showing
✅ **Driver Interface:** Can only check position (no registration)
✅ **Controller Login:** `madeya` / `CHANGE_ME`
✅ **Admin Login:** `admin` / `CHANGE_ME`
✅ **Golden Theme:** Applied everywhere
✅ **Sparkles:** Working on all pages

---

## 📞 NEED HELP?

If you have any issues:
1. Check this file for credentials
2. Verify database is imported
3. Clear browser cache
4. Check browser console for errors (F12)

---

**Your Etel Madeya system is ready to use! 🌟**
**የኢትል ማደያ ስርዓትዎ ለመጠቀም ዝግጁ ነው! ✨**
